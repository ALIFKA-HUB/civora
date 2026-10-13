import 'dart:async';

import 'package:civora_core/civora_core.dart';
import 'package:civora_ui/civora_ui.dart';
import 'package:flutter/material.dart';

import 'app_config.dart';
import 'features/auth/auth_screen.dart';
import 'features/home/home_screen.dart';
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
  }

  @override
  void dispose() {
    _apiClient.close();
    _sessionController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => MaterialApp(
    title: 'Civora ${widget.roleLabel}',
    debugShowCheckedModeBanner: false,
    locale: const Locale('id', 'ID'),
    theme: CivoraTheme.light,
    darkTheme: CivoraTheme.dark,
    themeMode: ThemePreference.system.themeMode,
    home: CivoraFoundationScreen(
      roleLabel: widget.roleLabel,
      apiClient: _apiClient,
      sessionController: _sessionController,
    ),
  );
}

class CivoraFoundationScreen extends StatefulWidget {
  const CivoraFoundationScreen({
    super.key,
    required this.roleLabel,
    required this.apiClient,
    required this.sessionController,
  });
  final String roleLabel;
  final ApiClient apiClient;
  final SessionController sessionController;

  @override
  State<CivoraFoundationScreen> createState() => _CivoraFoundationScreenState();
}

class _CivoraFoundationScreenState extends State<CivoraFoundationScreen> {
  @override
  void initState() {
    super.initState();
    unawaited(widget.sessionController.restore());
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
        return StudentTabs(api: widget.apiClient, session: session);
      }
      return StudentAuthScreen(session: session, api: widget.apiClient);
    },
  );
}

class StudentTabs extends StatefulWidget {
  const StudentTabs({super.key, required this.api, required this.session});
  final ApiClient api;
  final SessionController session;

  @override
  State<StudentTabs> createState() => _StudentTabsState();
}

class _StudentTabsState extends State<StudentTabs> {
  int _index = 0;

  String get _title =>
      const ['Beranda', 'Riwayat', 'Notifikasi', 'Profil'][_index];

  void _openQr() => Navigator.of(context).push<void>(
    MaterialPageRoute(builder: (_) => StudentQrScreen(api: widget.api)),
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
          const _NotificationsUnavailable(),
          StudentProfileScreen(session: widget.session, showAppBar: false),
        ],
      ),
      bottomNavigationBar: NavigationBar(
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

class _NotificationsUnavailable extends StatelessWidget {
  const _NotificationsUnavailable();
  @override
  Widget build(BuildContext context) => const Center(
    child: Padding(
      padding: EdgeInsets.all(24),
      child: Text('Notifikasi belum tersedia.'),
    ),
  );
}
