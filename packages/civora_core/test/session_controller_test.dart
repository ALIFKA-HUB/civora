import 'dart:async';
import 'dart:convert';

import 'package:civora_core/civora_core.dart';
import 'package:flutter_test/flutter_test.dart';

class MemoryTokenStore implements SessionTokenStore {
  final values = <String, String>{};
  @override
  Future<String?> read(String key) async => values[key];
  @override
  Future<void> write(String key, String token) async => values[key] = token;
  @override
  Future<void> delete(String key) async => values.remove(key);
}

class QueueTransport implements ApiTransport {
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

class DeferredFirstTransport implements ApiTransport {
  final firstResponse = Completer<ApiResponse>();
  final requests = <ApiRequest>[];
  final responses = <ApiResponse>[];
  @override
  Future<ApiResponse> send(ApiRequest request) async {
    requests.add(request);
    if (requests.length == 1) return firstResponse.future;
    return responses.removeAt(0);
  }

  @override
  void close() {}
}

class DelayedDeleteTokenStore implements SessionTokenStore {
  final values = <String, String>{};
  final deleteStarted = Completer<void>();
  final allowDelete = Completer<void>();
  @override
  Future<String?> read(String key) async => values[key];
  @override
  Future<void> write(String key, String token) async => values[key] = token;
  @override
  Future<void> delete(String key) async {
    if (!deleteStarted.isCompleted) deleteStarted.complete();
    await allowDelete.future;
    values.remove(key);
  }
}

ApiResponse jsonResponse(Map<String, dynamic> body, [int status = 200]) =>
    ApiResponse(status, jsonEncode(body));

void main() {
  test(
    'temporary credential requires activation and password change ends revoked session',
    () async {
      final store = MemoryTokenStore();
      final transport = QueueTransport()
        ..responses.add(
          jsonResponse({
            'data': {
              'token': 'temporary-token',
              'token_type': 'Bearer',
              'expires_at': '2026-10-20T00:00:00Z',
              'id': 'u1',
              'name': 'Siswa Uji',
              'role': 'student',
              'account_status': 'pending_activation',
              'must_change_password': true,
            },
          }),
        )
        ..responses.add(
          jsonResponse({
            'data': {
              'id': 'u1',
              'name': 'Siswa Uji',
              'role': 'student',
              'account_status': 'pending_activation',
              'must_change_password': true,
            },
          }),
        )
        ..responses.add(
          jsonResponse({
            'data': {
              'message': 'Password berhasil diganti. Silakan login kembali.',
            },
          }),
        );
      final session = SessionController(role: 'student', tokenStore: store);
      final api = ApiClient(
        baseUrl: Uri.parse('https://civora.test/api/v1'),
        transport: transport,
        sessionController: session,
      );
      session.bindApi(api);

      await session.login('0000000001', 'temporary', role: 'student');
      expect(session.state, SessionState.activationRequired);
      expect(store.values['civora.student.token'], 'temporary-token');
      expect(transport.requests[0].uri.path, '/api/v1/auth/student/login');
      expect(transport.requests[1].uri.path, '/api/v1/me');
      expect(
        transport.requests[1].headers['Authorization'],
        'Bearer temporary-token',
      );

      await session.changePassword('temporary', 'a long new password');
      expect(session.state, SessionState.unauthenticated);
      expect(store.values, isEmpty);
      expect(transport.requests.last.uri.path, '/api/v1/auth/change-password');
      expect(
        jsonDecode(transport.requests.last.body!)['password'],
        'a long new password',
      );
    },
  );

  test('wrong role response is rejected and token is never stored', () async {
    final store = MemoryTokenStore();
    final transport = QueueTransport()
      ..responses.add(
        jsonResponse({
          'data': {
            'token': 'wrong-role-token',
            'token_type': 'Bearer',
            'expires_at': '2026-10-20T00:00:00Z',
            'id': 'u1',
            'name': 'Guru',
            'role': 'teacher',
            'account_status': 'active',
            'must_change_password': false,
          },
        }),
      );
    final session = SessionController(role: 'student', tokenStore: store);
    final api = ApiClient(
      baseUrl: Uri.parse('https://civora.test/api/v1'),
      transport: transport,
      sessionController: session,
    );
    session.bindApi(api);
    await expectLater(
      session.login('id', 'password', role: 'student'),
      throwsA(isA<SessionRoleException>()),
    );
    expect(store.values, isEmpty);
    expect(session.state, SessionState.unauthenticated);
  });

  test(
    'restore validates server role and activation flag; separate app keys isolate storage',
    () async {
      final store = MemoryTokenStore()
        ..values['civora.student.token'] = 'student-token'
        ..values['civora.teacher.token'] = 'teacher-token';
      final transport = QueueTransport()
        ..responses.add(
          jsonResponse({
            'data': {
              'id': 'u1',
              'name': 'Siswa Uji',
              'role': 'student',
              'account_status': 'pending_activation',
              'must_change_password': true,
            },
          }),
        );
      final session = SessionController(role: 'student', tokenStore: store);
      final api = ApiClient(
        baseUrl: Uri.parse('https://civora.test/api/v1'),
        transport: transport,
        sessionController: session,
      );
      session.bindApi(api);
      await session.restore();
      expect(session.state, SessionState.activationRequired);
      expect(session.role, 'student');
      expect(
        transport.requests.single.headers['Authorization'],
        'Bearer student-token',
      );
      expect(store.values['civora.teacher.token'], 'teacher-token');
    },
  );

  test('restore rejects wrong role and removes saved token', () async {
    final store = MemoryTokenStore()
      ..values['civora.student.token'] = 'teacher-token';
    final transport = QueueTransport()
      ..responses.add(
        jsonResponse({
          'data': {
            'id': 'u2',
            'name': 'Guru',
            'role': 'teacher',
            'account_status': 'active',
            'must_change_password': false,
          },
        }),
      );
    final session = SessionController(role: 'student', tokenStore: store);
    final api = ApiClient(
      baseUrl: Uri.parse('https://civora.test/api/v1'),
      transport: transport,
      sessionController: session,
    );
    session.bindApi(api);
    await session.restore();
    expect(session.state, SessionState.unauthenticated);
    expect(store.values, isEmpty);
  });

  test(
    'logout calls revocation endpoint then clears server session and local token',
    () async {
      final store = MemoryTokenStore()
        ..values['civora.teacher.token'] = 'token';
      final transport = QueueTransport()
        ..responses.add(
          jsonResponse({
            'data': {
              'token': 'token',
              'token_type': 'Bearer',
              'expires_at': '2026-10-20T00:00:00Z',
              'id': 'u2',
              'name': 'Guru Uji',
              'role': 'teacher',
              'account_status': 'active',
              'must_change_password': false,
            },
          }),
        )
        ..responses.add(
          jsonResponse({
            'data': {
              'id': 'u2',
              'name': 'Guru Uji',
              'role': 'teacher',
              'account_status': 'active',
              'must_change_password': false,
            },
          }),
        )
        ..responses.add(
          jsonResponse({
            'data': {'message': 'Logout berhasil.'},
          }),
        );
      final session = SessionController(role: 'teacher', tokenStore: store);
      final api = ApiClient(
        baseUrl: Uri.parse('https://civora.test/api/v1'),
        transport: transport,
        sessionController: session,
      );
      session.bindApi(api);
      await session.login('G-002', 'password', role: 'teacher');
      await session.logout();
      expect(transport.requests.last.uri.path, '/api/v1/auth/logout');
      expect(transport.requests.last.headers['Authorization'], 'Bearer token');
      expect(session.state, SessionState.unauthenticated);
      expect(store.values, isEmpty);
    },
  );

  test('expired token returns to login and clears secure storage', () async {
    final store = MemoryTokenStore()
      ..values['civora.student.token'] = 'expired';
    final transport = QueueTransport()
      ..responses.add(
        jsonResponse({
          'error': {'code': 'UNAUTHENTICATED', 'message': 'Sesi habis.'},
        }, 401),
      );
    final session = SessionController(role: 'student', tokenStore: store);
    final api = ApiClient(
      baseUrl: Uri.parse('https://civora.test/api/v1'),
      transport: transport,
      sessionController: session,
    );
    session.bindApi(api);
    await session.restore();
    expect(session.state, SessionState.unauthenticated);
    expect(store.values, isEmpty);
  });

  test(
    'a delayed 401 from an older request cannot expire a newer login',
    () async {
      final store = MemoryTokenStore()
        ..values['civora.student.token'] = 'old-token';
      final transport = DeferredFirstTransport()
        ..responses.add(
          jsonResponse({
            'data': {
              'token': 'new-token',
              'token_type': 'Bearer',
              'expires_at': '2026-10-20T00:00:00Z',
              'id': 'u3',
              'name': 'Siswa Baru',
              'role': 'student',
              'account_status': 'active',
              'must_change_password': false,
            },
          }),
        )
        ..responses.add(
          jsonResponse({
            'data': {
              'id': 'u3',
              'name': 'Siswa Baru',
              'role': 'student',
              'account_status': 'active',
              'must_change_password': false,
            },
          }),
        );
      final session = SessionController(role: 'student', tokenStore: store)
        ..bearerToken = 'old-token'
        ..state = SessionState.authenticated;
      final api = ApiClient(
        baseUrl: Uri.parse('https://civora.test/api/v1'),
        transport: transport,
        sessionController: session,
      );
      session.bindApi(api);

      final oldRequest = api.get('/student/summary');
      await Future<void>.delayed(Duration.zero);
      await session.login('0000000003', 'new password', role: 'student');
      transport.firstResponse.complete(
        jsonResponse({
          'error': {'code': 'UNAUTHENTICATED', 'message': 'Expired'},
        }, 401),
      );
      await expectLater(oldRequest, throwsA(isA<ApiException>()));

      expect(
        transport.requests.first.headers['Authorization'],
        'Bearer old-token',
      );
      expect(session.bearerToken, 'new-token');
      expect(session.state, SessionState.authenticated);
      expect(store.values['civora.student.token'], 'new-token');
    },
  );

  test(
    'an in-flight token deletion cannot erase a newer login token',
    () async {
      final store = DelayedDeleteTokenStore()
        ..values['civora.student.token'] = 'old-token';
      final transport = DeferredFirstTransport()
        ..responses.add(
          jsonResponse({
            'data': {
              'token': 'new-token',
              'token_type': 'Bearer',
              'expires_at': '2026-10-20T00:00:00Z',
              'id': 'u4',
              'name': 'Siswa Baru',
              'role': 'student',
              'account_status': 'active',
              'must_change_password': false,
            },
          }),
        )
        ..responses.add(
          jsonResponse({
            'data': {
              'id': 'u4',
              'name': 'Siswa Baru',
              'role': 'student',
              'account_status': 'active',
              'must_change_password': false,
            },
          }),
        );
      final session = SessionController(role: 'student', tokenStore: store)
        ..bearerToken = 'old-token'
        ..state = SessionState.authenticated;
      final api = ApiClient(
        baseUrl: Uri.parse('https://civora.test/api/v1'),
        transport: transport,
        sessionController: session,
      );
      session.bindApi(api);

      final oldRequest = api.get('/student/summary');
      await Future<void>.delayed(Duration.zero);
      transport.firstResponse.complete(
        jsonResponse({
          'error': {'code': 'UNAUTHENTICATED', 'message': 'Expired'},
        }, 401),
      );
      await store.deleteStarted.future;
      final login = session.login(
        '0000000004',
        'new password',
        role: 'student',
      );
      store.allowDelete.complete();
      await expectLater(oldRequest, throwsA(isA<ApiException>()));
      await login;

      expect(session.state, SessionState.authenticated);
      expect(store.values['civora.student.token'], 'new-token');
    },
  );
}
