import 'dart:convert';

import 'package:civora_core/civora_core.dart';
import 'package:civora_student/app.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

class _MemoryStore implements SessionTokenStore {
  final values = <String, String>{};
  @override
  Future<String?> read(String key) async => values[key];
  @override
  Future<void> write(String key, String token) async => values[key] = token;
  @override
  Future<void> delete(String key) async => values.remove(key);
}

class _Transport implements ApiTransport {
  final requests = <ApiRequest>[];
  final responses = <ApiResponse>[];
  @override
  Future<ApiResponse> send(ApiRequest request) async {
    requests.add(request);
    return responses.removeAt(0);
  }

  @override
  void close() {}
}

ApiResponse _response(Map<String, dynamic> body) =>
    ApiResponse(200, jsonEncode(body));

void main() {
  testWidgets(
    'temporary student login routes to activation and successful change returns to login',
    (tester) async {
      final store = _MemoryStore();
      final transport = _Transport()
        ..responses.add(
          _response({
            'data': {
              'token': 'student-token',
              'token_type': 'Bearer',
              'expires_at': '2026-10-20T00:00:00Z',
              'id': 'student-1',
              'name': 'Siswa Uji',
              'role': 'student',
              'account_status': 'pending_activation',
              'must_change_password': true,
            },
          }),
        )
        ..responses.add(
          _response({
            'data': {
              'id': 'student-1',
              'name': 'Siswa Uji',
              'role': 'student',
              'account_status': 'pending_activation',
              'must_change_password': true,
            },
          }),
        )
        ..responses.add(
          _response({
            'data': {'message': 'Password berhasil diganti.'},
          }),
        );
      await tester.pumpWidget(
        CivoraApp(roleLabel: 'Siswa', transport: transport, tokenStore: store),
      );
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextFormField).at(0), '0000000001');
      await tester.enterText(
        find.byType(TextFormField).at(1),
        'temp credential',
      );
      await tester.tap(find.text('Masuk'));
      await tester.pumpAndSettle();

      expect(find.text('Aktivasi akun siswa'), findsOneWidget);
      expect(find.text('Aktifkan akun'), findsOneWidget);
      expect(find.byType(BottomNavigationBar), findsNothing);
      expect(find.textContaining('Tampilkan QR'), findsNothing);
      expect(find.textContaining('Catat'), findsNothing);
      expect(store.values['civora.student.token'], 'student-token');
      expect(transport.requests.first.uri.path, '/api/v1/auth/student/login');
      expect(
        transport.requests.first.headers,
        isNot(contains('Authorization')),
      );

      await tester.enterText(
        find.byType(TextFormField).at(0),
        'temp credential',
      );
      await tester.enterText(
        find.byType(TextFormField).at(1),
        'long secure password',
      );
      await tester.enterText(
        find.byType(TextFormField).at(2),
        'long secure password',
      );
      await tester.tap(find.text('Aktifkan akun'));
      await tester.pumpAndSettle();
      expect(find.text('Masuk sebagai siswa'), findsOneWidget);
      expect(find.textContaining('Masuk kembali'), findsOneWidget);
      expect(store.values, isEmpty);
      expect(transport.requests.last.uri.path, '/api/v1/auth/change-password');
      expect(
        transport.requests.last.headers['Authorization'],
        'Bearer student-token',
      );
    },
  );

  testWidgets(
    'student app rejects teacher role and does not expose protected navigation',
    (tester) async {
      final transport = _Transport()
        ..responses.add(
          _response({
            'data': {
              'token': 'wrong-role',
              'token_type': 'Bearer',
              'expires_at': '2026-10-20T00:00:00Z',
              'id': 'teacher-1',
              'name': 'Guru Uji',
              'role': 'teacher',
              'account_status': 'active',
              'must_change_password': false,
            },
          }),
        );
      final store = _MemoryStore();
      await tester.pumpWidget(
        CivoraApp(roleLabel: 'Siswa', transport: transport, tokenStore: store),
      );
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextFormField).at(0), 'G-001');
      await tester.enterText(find.byType(TextFormField).at(1), 'password');
      await tester.tap(find.text('Masuk'));
      await tester.pumpAndSettle();
      expect(
        find.textContaining('tidak sesuai dengan aplikasi siswa'),
        findsOneWidget,
      );
      expect(find.byType(BottomNavigationBar), findsNothing);
      expect(store.values, isEmpty);
      expect(
        transport.requests.single.headers,
        isNot(contains('Authorization')),
      );
    },
  );
}
