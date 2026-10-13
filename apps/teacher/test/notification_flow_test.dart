import 'dart:convert';

import 'package:civora_core/civora_core.dart';
import 'package:flutter_test/flutter_test.dart';

class _Store implements SessionTokenStore {
  String? token;
  @override
  Future<String?> read(String key) async => token;
  @override
  Future<void> write(String key, String value) async => token = value;
  @override
  Future<void> delete(String key) async => token = null;
}

class _Transport implements ApiTransport {
  final requests = <ApiRequest>[];
  final respond = <ApiResponse Function()>[];
  @override
  Future<ApiResponse> send(ApiRequest request) async {
    requests.add(request);
    return respond.removeAt(0)();
  }

  @override
  void close() {}
}

ApiResponse _ok(Object data, {int status = 200}) =>
    ApiResponse(status, jsonEncode({'data': data}));

class _Push implements NotificationPushClient {
  _Push({this.available = true});
  final bool available;
  final String? token = 'push-token';
  @override
  Future<void> initialize() async {
    if (!available) throw StateError('unavailable');
  }

  @override
  Future<bool> requestPermission() async => available;
  @override
  Future<String?> getToken() async => available ? token : null;
  @override
  Stream<String> get tokenRefreshes => const Stream.empty();
  @override
  Stream<Map<String, String>> get openedMessages => const Stream.empty();
  @override
  Stream<Map<String, String>> get foregroundMessages => const Stream.empty();
}

void main() {
  test('loads the in-app list when push is denied or unavailable', () async {
    final transport = _Transport()
      ..respond.add(
        () => _ok([
          {
            'id': 'n1',
            'type': 'point_record_created',
            'resource_id': '123e4567-e89b-42d3-a456-426614174000',
            'message': 'Catatan tersedia.',
            'created_at': '2026-10-13T00:00:00Z',
            'read_at': null,
          },
        ]),
      );
    final session = SessionController(role: 'student', tokenStore: _Store());
    final api = ApiClient(
      baseUrl: Uri.parse('https://civora.test/api/v1/'),
      transport: transport,
      sessionController: session,
    );
    session.bearerToken = 'session';
    final controller = NotificationController(
      api: api,
      session: session,
      push: _Push(available: false),
    );
    await controller.initializePush();
    await controller.load();
    expect(controller.notifications, hasLength(1));
    expect(transport.requests.single.uri.path, endsWith('/notifications'));
    controller.dispose();
    api.close();
    session.dispose();
  });

  test(
    'a push tap while logged out requests login before any resource fetch',
    () async {
      final transport = _Transport();
      final session = SessionController(role: 'student', tokenStore: _Store());
      final api = ApiClient(
        baseUrl: Uri.parse('https://civora.test/api/v1/'),
        transport: transport,
        sessionController: session,
      );
      final controller = NotificationController(
        api: api,
        session: session,
        push: _Push(),
      );
      await expectLater(
        controller.openPushedResource(
          'point_record_created',
          '123e4567-e89b-42d3-a456-426614174000',
        ),
        throwsA(isA<NotificationLoginRequired>()),
      );
      expect(transport.requests, isEmpty);
      controller.dispose();
      api.close();
      session.dispose();
    },
  );

  test(
    'a student cannot open another student record from a notification',
    () async {
      final id = '123e4567-e89b-42d3-a456-426614174000';
      final transport = _Transport()
        ..respond.add(() => _ok({'token': 'session', 'role': 'student'}))
        ..respond.add(
          () => _ok({
            'id': 'u1',
            'name': 'Siswa',
            'role': 'student',
            'account_status': 'active',
            'must_change_password': false,
          }),
        )
        ..respond.add(
          () => _ok([
            {
              'id': 'n1',
              'type': 'point_record_created',
              'resource_id': id,
              'message': 'Catatan tersedia.',
              'created_at': '2026-10-13T00:00:00Z',
            },
          ]),
        )
        ..respond.add(() => _ok({'message': 'not found'}, status: 404));
      final session = SessionController(role: 'student', tokenStore: _Store());
      final api = ApiClient(
        baseUrl: Uri.parse('https://civora.test/api/v1/'),
        transport: transport,
        sessionController: session,
      )..sessionController;
      session.bindApi(api);
      await session.login('0000000001', 'password', role: 'student');
      final controller = NotificationController(
        api: api,
        session: session,
        push: _Push(),
      );
      await expectLater(
        controller.openPushedResource('point_record_created', id),
        throwsA(isA<ApiException>()),
      );
      expect(
        transport.requests.last.uri.path,
        endsWith('/student/records/$id'),
      );
      controller.dispose();
      api.close();
      session.dispose();
    },
  );

  test(
    'logout removes the registered device token before ending the session',
    () async {
      final transport = _Transport()
        ..respond.add(() => _ok({'token': 'session', 'role': 'student'}))
        ..respond.add(
          () => _ok({
            'id': 'u1',
            'name': 'Siswa',
            'role': 'student',
            'account_status': 'active',
            'must_change_password': false,
          }),
        )
        ..respond.add(() => _ok({'id': 'device-id', 'app': 'student'}))
        ..respond.add(() => _ok({'message': 'removed'}))
        ..respond.add(() => _ok({'message': 'logged out'}));
      final session = SessionController(role: 'student', tokenStore: _Store());
      final api = ApiClient(
        baseUrl: Uri.parse('https://civora.test/api/v1/'),
        transport: transport,
        sessionController: session,
      );
      session.bindApi(api);
      final controller = NotificationController(
        api: api,
        session: session,
        push: _Push(),
      );
      session.beforeLogout = () => controller.unregisterDevice();
      await session.login('0000000001', 'password', role: 'student');
      await controller.initializePush();
      await session.logout();
      final paths = transport.requests.map((e) => e.uri.path).toList();
      expect(paths.skip(paths.length - 2), [
        endsWith('/device-tokens/device-id'),
        endsWith('/auth/logout'),
      ]);
      controller.dispose();
      api.close();
      session.dispose();
    },
  );
}
