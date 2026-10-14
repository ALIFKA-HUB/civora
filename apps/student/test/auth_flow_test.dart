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

ApiResponse _homeSummary() => _response({
  'data': {'semester': null, 'totals': null, 'latest_threshold_status': null},
});

ApiResponse _homeRecords() => _response({
  'data': [],
  'meta': {
    'current_page': 1,
    'last_page': 1,
    'per_page': 20,
    'total': 0,
    'available_semesters': [],
  },
});

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

  testWidgets('pending student can log out from activation mode', (
    tester,
  ) async {
    final store = _MemoryStore();
    final transport = _Transport()
      ..responses.add(
        _response({
          'data': {
            'token': 'pending-token',
            'token_type': 'Bearer',
            'expires_at': '2026-10-20T00:00:00Z',
            'id': 'pending-1',
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
            'id': 'pending-1',
            'name': 'Siswa Uji',
            'role': 'student',
            'account_status': 'pending_activation',
            'must_change_password': true,
          },
        }),
      )
      ..responses.add(
        _response({
          'data': {'message': 'Logout berhasil.'},
        }),
      );
    await tester.pumpWidget(
      CivoraApp(roleLabel: 'Siswa', transport: transport, tokenStore: store),
    );
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextFormField).at(0), '0000000001');
    await tester.enterText(find.byType(TextFormField).at(1), 'temporary');
    await tester.tap(find.text('Masuk'));
    await tester.pumpAndSettle();

    await tester.tap(find.byTooltip('Keluar'));
    await tester.pumpAndSettle();
    expect(find.text('Masuk sebagai siswa'), findsOneWidget);
    expect(transport.requests.last.uri.path, '/api/v1/auth/logout');
    expect(
      transport.requests.last.headers['Authorization'],
      'Bearer pending-token',
    );
    expect(store.values, isEmpty);
  });

  testWidgets(
    'student profile leaves navigation after logout and password change',
    (tester) async {
      final store = _MemoryStore()
        ..values['civora.student.token'] = 'active-token';
      final activeMe = {
        'data': {
          'id': 'student-2',
          'name': 'Siswa Rahasia',
          'role': 'student',
          'account_status': 'active',
          'must_change_password': false,
          'student': {
            'id': 's2',
            'nisn': '0000000002',
            'education_status': 'active',
          },
        },
      };
      final transport = _Transport()
        ..responses.add(_response(activeMe))
        ..responses.add(_homeSummary())
        ..responses.add(_homeRecords())
        ..responses.add(_homeRecords())
        ..responses.add(
          _response({
            'data': {'message': 'Logout berhasil.'},
          }),
        );
      await tester.pumpWidget(
        CivoraApp(roleLabel: 'Siswa', transport: transport, tokenStore: store),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byIcon(Icons.person_outline));
      await tester.pumpAndSettle();
      expect(find.text('Siswa Rahasia'), findsOneWidget);
      await tester.drag(find.byType(ListView).last, const Offset(0, -700));
      await tester.pumpAndSettle();
      expect(find.text('Keluar'), findsOneWidget);
      await tester.tap(find.text('Keluar'));
      await tester.pumpAndSettle();
      expect(find.text('Masuk sebagai siswa'), findsOneWidget);
      expect(find.text('Siswa Rahasia'), findsNothing);

      final secondStore = _MemoryStore()
        ..values['civora.student.token'] = 'active-token-2';
      final secondTransport = _Transport()
        ..responses.add(_response(activeMe))
        ..responses.add(_homeSummary())
        ..responses.add(_homeRecords())
        ..responses.add(_homeRecords())
        ..responses.add(
          _response({
            'data': {'message': 'Password berhasil diganti.'},
          }),
        );
      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pumpWidget(
        CivoraApp(
          roleLabel: 'Siswa',
          transport: secondTransport,
          tokenStore: secondStore,
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byIcon(Icons.person_outline));
      await tester.pumpAndSettle();
      await tester.drag(find.byType(ListView).last, const Offset(0, -500));
      await tester.pumpAndSettle();
      expect(find.text('Ganti password'), findsWidgets);
      await tester.enterText(
        find.byType(TextFormField).at(0),
        'current password',
      );
      await tester.enterText(
        find.byType(TextFormField).at(1),
        'new secure password',
      );
      await tester.enterText(
        find.byType(TextFormField).at(2),
        'new secure password',
      );
      await tester.tap(find.text('Ganti password').last);
      await tester.pumpAndSettle();
      expect(find.text('Masuk sebagai siswa'), findsOneWidget);
      expect(find.text('Siswa Rahasia'), findsNothing);
    },
  );

  testWidgets(
    'expired student session removes profile route and account details',
    (tester) async {
      final store = _MemoryStore()
        ..values['civora.student.token'] = 'expired-token';
      final transport = _Transport()
        ..responses.add(
          _response({
            'data': {
              'id': 'student-3',
              'name': 'Siswa Privat',
              'role': 'student',
              'account_status': 'active',
              'must_change_password': false,
              'student': {
                'id': 's3',
                'nisn': '0000000003',
                'education_status': 'active',
              },
            },
          }),
        )
        ..responses.add(_homeSummary())
        ..responses.add(_homeRecords())
        ..responses.add(_homeRecords())
        ..responses.add(
          ApiResponse(
            401,
            jsonEncode({
              'error': {'code': 'UNAUTHENTICATED', 'message': 'Sesi habis.'},
            }),
          ),
        );
      await tester.pumpWidget(
        CivoraApp(roleLabel: 'Siswa', transport: transport, tokenStore: store),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byIcon(Icons.person_outline));
      await tester.pumpAndSettle();
      final root = tester.widget<CivoraFoundationScreen>(
        find.byType(CivoraFoundationScreen, skipOffstage: false),
      );
      await expectLater(
        root.apiClient.get('/me'),
        throwsA(isA<ApiException>()),
      );
      await tester.pumpAndSettle();
      expect(find.text('Masuk sebagai siswa'), findsOneWidget);
      expect(find.text('Siswa Privat'), findsNothing);
      expect(store.values, isEmpty);
    },
  );
}
