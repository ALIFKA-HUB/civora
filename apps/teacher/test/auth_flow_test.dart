import 'dart:convert';

import 'package:civora_core/civora_core.dart';
import 'package:civora_teacher/app.dart';
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
    'teacher uses fixed teacher login and activation gate until password change succeeds',
    (tester) async {
      final store = _MemoryStore();
      final transport = _Transport()
        ..responses.add(
          _response({
            'data': {
              'token': 'teacher-token',
              'token_type': 'Bearer',
              'expires_at': '2026-10-20T00:00:00Z',
              'id': 'teacher-1',
              'name': 'Guru Uji',
              'role': 'teacher',
              'account_status': 'pending_activation',
              'must_change_password': true,
            },
          }),
        )
        ..responses.add(
          _response({
            'data': {
              'id': 'teacher-1',
              'name': 'Guru Uji',
              'role': 'teacher',
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
        CivoraApp(roleLabel: 'Guru', transport: transport, tokenStore: store),
      );
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextFormField).at(0), 'G-001');
      await tester.enterText(
        find.byType(TextFormField).at(1),
        'temp credential',
      );
      await tester.tap(find.text('Masuk'));
      await tester.pumpAndSettle();

      expect(find.text('Aktivasi akun guru'), findsOneWidget);
      expect(find.text('Aktifkan akun'), findsOneWidget);
      expect(find.byType(BottomNavigationBar), findsNothing);
      expect(find.textContaining('Scan QR'), findsNothing);
      expect(find.textContaining('Catat Poin'), findsNothing);
      expect(store.values['civora.teacher.token'], 'teacher-token');
      expect(transport.requests.first.uri.path, '/api/v1/auth/teacher/login');
      expect(jsonDecode(transport.requests.first.body!)['identifier'], 'G-001');
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
      expect(find.text('Masuk sebagai guru'), findsOneWidget);
      expect(store.values, isEmpty);
      expect(transport.requests.last.uri.path, '/api/v1/auth/change-password');
      expect(
        transport.requests.last.headers['Authorization'],
        'Bearer teacher-token',
      );
    },
  );
}
