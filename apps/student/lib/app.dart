import 'dart:async';

import 'package:civora_core/civora_core.dart';
import 'package:civora_ui/civora_ui.dart';
import 'package:flutter/material.dart';

import 'app_config.dart';
import 'features/auth/auth_screen.dart';
import 'features/home/home_screen.dart';
import 'features/notifications/notifications_screen.dart';
import 'features/profile/profile_screen.dart';
import 'features/qr/qr_screen.dart';
import 'features/records/record_detail_screen.dart';
import 'features/records/records_screen.dart';

class CivoraApp extends StatefulWidget {
  const CivoraApp({
    super.key,
    required this.roleLabel,
    this.config,
    this.transport,
    this.tokenStore,
  });
  final String roleLabel;
  final CivoraAppConfig? config;
  final ApiTransport? transport;
  final SessionTokenStore? tokenStore;

  @override
  State<CivoraApp> createState() => _CivoraAppState();
}

class _CivoraAppState extends State<CivoraApp> {
  late final ApiClient _apiClient;
  late final SessionController _sessionController;
  late final NotificationController _notifications;
  late final ThemePreferenceController _themePreference;

  @override
  void initState() {
    super.initState();
    _sessionController = SessionController(
      role: 'student',
      tokenStore:
          widget.tokenStore ?? FlutterSecureSessionTokenStore(role: 'student'),
    );
    _apiClient = ApiClient(
      baseUrl: (widget.config ?? CivoraAppConfig.fromEnvironment()).apiBaseUrl,
      transport: widget.transport,
      sessionController: _sessionController,
    );
    _sessionController.bindApi(_apiClient);
    _notifications = NotificationController(
      api: _apiClient,
      session: _sessionController,
      push: FirebaseNotificationPushClient(),
    );
    _sessionController.beforeLogout = _notifications.unregisterDevice;
    _themePreference = ThemePreferenceController();
    unawaited(_notifications.initializePush());
  }

  @override
  void dispose() {
    _apiClient.close();
    _sessionController.dispose();
    _notifications.dispose();
    _themePreference.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: _themePreference,
    builder: (context, _) => MaterialApp(
      title: 'Civora ${widget.roleLabel}',
      debugShowCheckedModeBanner: false,
      locale: const Locale('id', 'ID'),
      theme: CivoraTheme.light,
      darkTheme: CivoraTheme.dark,
      themeMode: _themePreference.preference.themeMode,
      home: CivoraFoundationScreen(
        roleLabel: widget.roleLabel,
        apiClient: _apiClient,
        sessionController: _sessionController,
        notifications: _notifications,
        themePreference: _themePreference,
      ),
    ),
  );
}

class CivoraFoundationScreen extends StatefulWidget {
  const CivoraFoundationScreen({
    super.key,
    required this.roleLabel,
    required this.apiClient,
    required this.sessionController,
    required this.notifications,
    required this.themePreference,
  });
  final String roleLabel;
  final ApiClient apiClient;
  final SessionController sessionController;
  final NotificationController notifications;
  final ThemePreferenceController themePreference;

  @override
  State<CivoraFoundationScreen> createState() => _CivoraFoundationScreenState();
}

class _CivoraFoundationScreenState extends State<CivoraFoundationScreen> {
  @override
  void initState() {
    super.initState();
    unawaited(widget.sessionController.restore());
    widget.notifications.onPushedResource = (type, id) async {
      if (!mounted ||
          widget.sessionController.state != SessionState.authenticated) {
        return;
      }
      Navigator.of(context).push<void>(
        MaterialPageRoute(
          builder: (_) => StudentRecordDetailScreen(
            api: widget.apiClient,
            recordId: id,
            schoolTimezone: widget.sessionController.profile?.schoolTimezone,
          ),
        ),
      );
    };
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: widget.sessionController,
    builder: (context, _) {
      final session = widget.sessionController;
      if (session.isRestoring) {
        return const Scaffold(
          body: Center(
            child: CircularProgressIndicator(semanticsLabel: 'Memulihkan sesi'),
          ),
        );
      }
      if (session.state == SessionState.activationRequired) {
        return StudentAuthScreen(
          session: session,
          api: widget.apiClient,
          activation: true,
        );
      }
      if (session.state == SessionState.authenticated) {
        return StudentTabs(
          api: widget.apiClient,
          session: session,
          notifications: widget.notifications,
          themePreference: widget.themePreference,
        );
      }
      return AnimatedBuilder(
        animation: widget.notifications,
        builder: (context, _) => Column(
          children: [
            if (widget.notifications.loginRequested)
              const Padding(
                padding: EdgeInsets.all(12),
                child: Text(
                  'Masuk kembali untuk memeriksa notifikasi pada akun ini.',
                ),
              ),
            Expanded(
              child: StudentAuthScreen(session: session, api: widget.apiClient),
            ),
          ],
        ),
      );
    },
  );
}

class StudentTabs extends StatefulWidget {
  const StudentTabs({
    super.key,
    required this.api,
    required this.session,
    required this.notifications,
    required this.themePreference,
  });
  final ApiClient api;
  final SessionController session;
  final NotificationController notifications;
  final ThemePreferenceController themePreference;

  @override
  State<StudentTabs> createState() => _StudentTabsState();
}

class _StudentTabsState extends State<StudentTabs> {
  int _index = 0;

  String get _title =>
      const ['Beranda', 'Riwayat', 'Notifikasi', 'Profil'][_index];

  void _openQr() => Navigator.of(context).push<void>(
    MaterialPageRoute(
      builder: (_) => StudentQrScreen(
        api: widget.api,
        studentName: widget.session.profile!.account.name,
        nisn: widget.session.profile!.student?.nisn ?? 'Tidak tersedia',
        classLabel:
            widget.session.profile!.ownEnrollment?.classroom.name ??
            'Kelas belum tersedia',
      ),
    ),
  );

  void _openRecord(String id) => Navigator.of(context).push<void>(
    MaterialPageRoute(
      builder: (_) => StudentRecordDetailScreen(
        api: widget.api,
        recordId: id,
        schoolTimezone: widget.session.profile?.schoolTimezone,
      ),
    ),
  );

  @override
  Widget build(BuildContext context) {
    final profile = widget.session.profile!;
    return Scaffold(
      appBar: AppBar(title: Text(_title)),
      body: IndexedStack(
        index: _index,
        children: [
          StudentHomeScreen(
            api: widget.api,
            profile: profile,
            onOpenQr: _openQr,
            onOpenRecord: _openRecord,
            onOpenHistory: () => setState(() => _index = 1),
          ),
          StudentRecordsScreen(
            api: widget.api,
            schoolTimezone: profile.schoolTimezone,
            onOpenRecord: _openRecord,
          ),
          StudentNotificationsScreen(
            controller: widget.notifications,
            onOpenRecord: _openRecord,
            active: _index == 2,
          ),
          StudentProfileScreen(
            session: widget.session,
            showAppBar: false,
            themePreference: widget.themePreference,
          ),
        ],
      ),
      bottomNavigationBar: CivoraCapsuleNavigationBar(
        selectedIndex: _index,
        onDestinationSelected: (index) => setState(() => _index = index),
        destinations: const [
          NavigationDestination(
            icon: Icon(Icons.home_outlined),
            selectedIcon: Icon(Icons.home),
            label: 'Beranda',
          ),
          NavigationDestination(icon: Icon(Icons.history), label: 'Riwayat'),
          NavigationDestination(
            icon: Icon(Icons.notifications_outlined),
            label: 'Notifikasi',
          ),
          NavigationDestination(
            icon: Icon(Icons.person_outline),
            selectedIcon: Icon(Icons.person),
            label: 'Profil',
          ),
        ],
      ),
    );
  }
}
