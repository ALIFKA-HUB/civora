import 'dart:async';

import 'package:civora_core/civora_core.dart';
import 'package:civora_ui/civora_ui.dart';
import 'package:flutter/material.dart';

import 'app_config.dart';
import 'features/auth/auth_screen.dart';
import 'features/profile/profile_screen.dart';

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
      role: 'teacher',
      tokenStore:
          widget.tokenStore ?? FlutterSecureSessionTokenStore(role: 'teacher'),
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
        return TeacherAuthScreen(
          session: session,
          api: widget.apiClient,
          activation: true,
        );
      }
      if (session.state == SessionState.authenticated) {
        return Scaffold(
          appBar: AppBar(
            title: Text('Civora ${widget.roleLabel}'),
            actions: [
              IconButton(
                tooltip: 'Profil',
                icon: const Icon(Icons.account_circle_outlined),
                onPressed: _openProfile,
              ),
            ],
          ),
          body: Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(
                    Icons.school_outlined,
                    size: 48,
                    color: Theme.of(context).colorScheme.primary,
                  ),
                  const SizedBox(height: 16),
                  Text(
                    'Halo, ${session.profile?.account.name ?? 'Guru'}',
                    style: Theme.of(context).textTheme.titleLarge,
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: 8),
                  const Text('Sesi guru aktif.'),
                ],
              ),
            ),
          ),
        );
      }
      return TeacherAuthScreen(session: session, api: widget.apiClient);
    },
  );

  void _openProfile() => Navigator.of(context).push(
    MaterialPageRoute<void>(
      builder: (_) => TeacherProfileScreen(session: widget.sessionController),
    ),
  );
}
