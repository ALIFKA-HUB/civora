import 'dart:async';
import 'dart:convert';

import 'package:civora_core/civora_core.dart';
import 'package:civora_teacher/features/students/temporary_credential_dialog.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

class _Store implements SessionTokenStore {
  @override
  Future<String?> read(String key) async => null;
  @override
  Future<void> write(String key, String token) async {}
  @override
  Future<void> delete(String key) async {}
}

class _Transport implements ApiTransport {
  final requests = <ApiRequest>[];
  final responses = <Future<ApiResponse> Function()>[];
  @override
  Future<ApiResponse> send(ApiRequest request) async {
    requests.add(request);
    return responses.removeAt(0)();
  }

  @override
  void close() {}
}

ApiResponse _ok(Object data) => ApiResponse(200, jsonEncode({'data': data}));
ApiClient _api(_Transport transport) => ApiClient(
  baseUrl: Uri.parse('https://civora.test/api/v1/'),
  transport: transport,
);

void main() {
  test(
    'temporary credentials require fresh teacher password and return only the valid secret',
    () async {
      final transport = _Transport()
        ..responses.add(
          () async => _ok({
            'password': 'temporary-only',
            'expires_at': '2026-10-14T00:00:00Z',
          }),
        );
      final helper = TeacherCredentialHelpController(
        api: _api(transport),
        studentId: 'student-id',
        accountStatus: 'pending_activation',
        mustChangePassword: true,
      );
      expect(helper.canReveal, isTrue);
      final credential = await helper.reveal('current teacher password');
      expect(credential?.password, 'temporary-only');
      expect(credential?.expiresAt.isUtc, isTrue);
      expect(
        jsonDecode(transport.requests.single.body!)['current_password'],
        'current teacher password',
      );
      expect(
        transport.requests.single.uri.path,
        endsWith('/teacher/students/student-id/temporary-credential/reveal'),
      );
    },
  );

  test(
    'active student sees status without a password or a reveal request',
    () async {
      final transport = _Transport();
      final helper = TeacherCredentialHelpController(
        api: _api(transport),
        studentId: 'student-id',
        accountStatus: 'active',
        mustChangePassword: false,
      );
      expect(helper.canReveal, isFalse);
      expect(helper.statusMessage, contains('aktif'));
      expect(await helper.reveal('current teacher password'), isNull);
      expect(transport.requests, isEmpty);
    },
  );

  test(
    'expired credential remains unavailable and does not retain a secret',
    () async {
      final transport = _Transport()
        ..responses.add(
          () async => ApiResponse(
            410,
            jsonEncode({
              'error': {'message': 'Password sementara sudah kedaluwarsa.'},
            }),
          ),
        );
      final helper = TeacherCredentialHelpController(
        api: _api(transport),
        studentId: 'student-id',
        accountStatus: 'pending_activation',
        mustChangePassword: true,
      );
      await expectLater(
        helper.reveal('current teacher password'),
        throwsA(isA<ApiException>()),
      );
      expect(helper.errorMessage, contains('kedaluwarsa'));
      expect(
        jsonDecode(transport.requests.single.body!)['current_password'],
        'current teacher password',
      );
    },
  );

  test('missing reauthentication password sends no request', () async {
    final transport = _Transport();
    final helper = TeacherCredentialHelpController(
      api: _api(transport),
      studentId: 'student-id',
      accountStatus: 'pending_activation',
      mustChangePassword: true,
    );
    await expectLater(
      helper.reveal(''),
      throwsA(isA<PasswordRequiredException>()),
    );
    expect(transport.requests, isEmpty);
  });

  testWidgets(
    'a late reveal response cannot restore the credential after backgrounding',
    (tester) async {
      final response = Completer<ApiResponse>();
      final transport = _Transport()..responses.add(() => response.future);
      final session = SessionController(role: 'teacher', tokenStore: _Store())
        ..state = SessionState.authenticated
        ..bearerToken = 'teacher-session';
      final helper = TeacherCredentialHelpController(
        api: _api(transport),
        studentId: 'student-id',
        accountStatus: 'pending_activation',
        mustChangePassword: true,
      );
      await tester.pumpWidget(
        MaterialApp(
          home: Builder(
            builder: (context) => TextButton(
              onPressed: () => showDialog<void>(
                context: context,
                builder: (_) => TemporaryCredentialDialog(
                  controller: helper,
                  session: session,
                ),
              ),
              child: const Text('Open'),
            ),
          ),
        ),
      );
      await tester.tap(find.text('Open'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byType(TextField),
        'current teacher password',
      );
      await tester.tap(find.text('Tampilkan password sementara'));
      await tester.pump();
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      response.complete(
        _ok({
          'password': 'temporary-only',
          'expires_at': '2026-10-14T00:00:00Z',
        }),
      );
      await tester.pumpAndSettle();
      expect(find.text('temporary-only'), findsNothing);
      expect(
        tester.widget<TextField>(find.byType(TextField)).controller!.text,
        isEmpty,
      );
      session.dispose();
    },
  );

  testWidgets(
    'closing the dialog clears reauthentication input and blocks a late secret',
    (tester) async {
      final response = Completer<ApiResponse>();
      final transport = _Transport()..responses.add(() => response.future);
      final session = SessionController(role: 'teacher', tokenStore: _Store())
        ..state = SessionState.authenticated
        ..bearerToken = 'teacher-session';
      final helper = TeacherCredentialHelpController(
        api: _api(transport),
        studentId: 'student-id',
        accountStatus: 'pending_activation',
        mustChangePassword: true,
      );
      await tester.pumpWidget(
        MaterialApp(
          home: Builder(
            builder: (context) => TextButton(
              onPressed: () => showDialog<void>(
                context: context,
                builder: (_) => TemporaryCredentialDialog(
                  controller: helper,
                  session: session,
                ),
              ),
              child: const Text('Open'),
            ),
          ),
        ),
      );
      await tester.tap(find.text('Open'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byType(TextField),
        'current teacher password',
      );
      final field = tester
          .widget<TextField>(find.byType(TextField))
          .controller!;
      await tester.tap(find.text('Tampilkan password sementara'));
      await tester.pump();
      await tester.tap(find.text('Tutup'));
      await tester.pumpAndSettle();
      expect(field.text, isEmpty);

      response.complete(
        _ok({
          'password': 'temporary-only',
          'expires_at': '2026-10-14T00:00:00Z',
        }),
      );
      await tester.pumpAndSettle();
      expect(find.text('temporary-only'), findsNothing);
      expect(find.text('Bantuan akun siswa'), findsNothing);
      session.dispose();
    },
  );
}
