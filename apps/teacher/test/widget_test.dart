import 'dart:convert';

import 'package:civora_core/civora_core.dart';
import 'package:civora_teacher/app.dart';
import 'package:civora_teacher/app_config.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

class RecordingTransport implements ApiTransport {
  final requests = <ApiRequest>[];
  var closed = false;

  @override
  Future<ApiResponse> send(ApiRequest request) async {
    requests.add(request);
    return ApiResponse(200, jsonEncode({'status': 'ok'}));
  }

  @override
  void close() => closed = true;
}

void main() {
  testWidgets('teacher composition root uses configured base URL and owns one client', (tester) async {
    final firstTransport = RecordingTransport();
    await tester.pumpWidget(CivoraApp(
      key: const ValueKey('teacher-local'),
      roleLabel: 'Guru',
      config: CivoraAppConfig(apiBaseUrl: Uri.parse('https://teacher.local.test/api/v1')),
      transport: firstTransport,
    ));
    final firstClient = tester.widget<CivoraFoundationScreen>(find.byType(CivoraFoundationScreen)).apiClient;
    await firstClient.get('/health');
    expect(firstTransport.requests.single.uri, Uri.parse('https://teacher.local.test/api/v1/health'));

    await tester.pumpWidget(CivoraApp(
      key: const ValueKey('teacher-local'),
      roleLabel: 'Guru diperbarui',
      config: CivoraAppConfig(apiBaseUrl: Uri.parse('https://ignored-on-rebuild.test/api/v1')),
      transport: RecordingTransport(),
    ));
    final rebuiltClient = tester.widget<CivoraFoundationScreen>(find.byType(CivoraFoundationScreen)).apiClient;
    expect(identical(firstClient, rebuiltClient), isTrue);
    await rebuiltClient.get('/health');
    expect(firstTransport.requests.last.uri, Uri.parse('https://teacher.local.test/api/v1/health'));

    final secondTransport = RecordingTransport();
    await tester.pumpWidget(CivoraApp(
      key: const ValueKey('teacher-staging'),
      roleLabel: 'Guru',
      config: CivoraAppConfig(apiBaseUrl: Uri.parse('https://teacher.staging.test/api/v1')),
      transport: secondTransport,
    ));
    final secondClient = tester.widget<CivoraFoundationScreen>(find.byType(CivoraFoundationScreen)).apiClient;
    await secondClient.get('/health');
    expect(secondTransport.requests.single.uri, Uri.parse('https://teacher.staging.test/api/v1/health'));
    expect(identical(firstClient, secondClient), isFalse);
    expect(firstTransport.closed, isTrue);

    await tester.pumpWidget(const SizedBox.shrink());
    expect(secondTransport.closed, isTrue);
  });

  testWidgets('teacher shell retains its fixed role label', (tester) async {
    await tester.pumpWidget(const CivoraApp(roleLabel: 'Guru'));
    expect(find.text('Civora Guru'), findsOneWidget);
    expect(find.text('Fondasi aplikasi Guru'), findsOneWidget);
  });

  testWidgets('teacher root uses the compile-time API base URL when config is omitted', (tester) async {
    final transport = RecordingTransport();
    await tester.pumpWidget(CivoraApp(roleLabel: 'Guru', transport: transport));
    final client = tester.widget<CivoraFoundationScreen>(find.byType(CivoraFoundationScreen)).apiClient;
    await client.get('/health');
    expect(transport.requests.single.uri, Uri.parse('https://teacher.compiled.test/api/v1/health'));
  });
}
