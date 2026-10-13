import 'package:civora_core/civora_core.dart';
import 'package:civora_ui/civora_ui.dart';
import 'package:flutter/material.dart';

import 'app_config.dart';

class CivoraApp extends StatefulWidget {
  const CivoraApp({
    super.key,
    required this.roleLabel,
    this.config,
    this.transport,
  });

  final String roleLabel;
  final CivoraAppConfig? config;
  final ApiTransport? transport;

  @override
  State<CivoraApp> createState() => _CivoraAppState();
}

class _CivoraAppState extends State<CivoraApp> {
  late final ApiClient _apiClient;
  late final SessionController _sessionController;

  @override
  void initState() {
    super.initState();
    _sessionController = SessionController();
    _apiClient = ApiClient(
      baseUrl: (widget.config ?? CivoraAppConfig.fromEnvironment()).apiBaseUrl,
      transport: widget.transport,
      sessionController: _sessionController,
    );
  }

  @override
  void dispose() {
    _apiClient.close();
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

class CivoraFoundationScreen extends StatelessWidget {
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
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: Text('Civora $roleLabel')),
    body: Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.school_outlined, size: 48, color: Theme.of(context).colorScheme.primary),
            const SizedBox(height: 16),
            Text('Fondasi aplikasi $roleLabel', style: Theme.of(context).textTheme.titleLarge, textAlign: TextAlign.center),
            const SizedBox(height: 8),
            const Text('Fitur aplikasi akan hadir bertahap.', textAlign: TextAlign.center),
          ],
        ),
      ),
    ),
  );
}
