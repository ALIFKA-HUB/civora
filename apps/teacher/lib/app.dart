import 'package:flutter/material.dart';
import 'package:civora_ui/civora_ui.dart';

class CivoraApp extends StatelessWidget {
  const CivoraApp({super.key, required this.roleLabel});

  final String roleLabel;

  @override
  Widget build(BuildContext context) => MaterialApp(
    title: 'Civora $roleLabel',
    debugShowCheckedModeBanner: false,
    locale: const Locale('id', 'ID'),
    theme: CivoraTheme.light,
    darkTheme: CivoraTheme.dark,
    themeMode: ThemePreference.system.themeMode,
    home: CivoraFoundationScreen(roleLabel: roleLabel),
  );
}

class CivoraFoundationScreen extends StatelessWidget {
  const CivoraFoundationScreen({super.key, required this.roleLabel});

  final String roleLabel;

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
