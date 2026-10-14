import 'dart:async';
import 'dart:convert';

import 'package:civora_core/civora_core.dart';
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
  final pending = <Completer<ApiResponse>>[];

  @override
  Future<ApiResponse> send(ApiRequest request) {
    requests.add(request);
    final response = Completer<ApiResponse>();
    pending.add(response);
    return response.future;
  }

  @override
  void close() {}
}

class _InitialMessagePush implements NotificationPushClient {
  _InitialMessagePush(this.message);

  final Map<String, String> message;
  final _opened = StreamController<Map<String, String>>.broadcast();

  @override
  Future<void> initialize() async {
    _opened.add(message);
  }

  @override
  Future<bool> requestPermission() async => true;

  @override
  Future<String?> getToken() async => null;

  @override
  Stream<String> get tokenRefreshes => const Stream.empty();

  @override
  Stream<Map<String, String>> get openedMessages => _opened.stream;

  @override
  Stream<Map<String, String>> get foregroundMessages => const Stream.empty();

  Future<void> close() => _opened.close();
}

ApiResponse _ok(String id) => ApiResponse(
  200,
  jsonEncode({
    'data': [
      {
        'id': id,
        'type': 'point_record_created',
        'resource_id': '123e4567-e89b-42d3-a456-426614174000',
        'message': 'Notifikasi $id',
        'created_at': '2026-10-13T00:00:00Z',
        'read_at': null,
      },
    ],
  }),
);

void _loginAs(SessionController session, String token) {
  session.state = SessionState.authenticated;
  session.bearerToken = token;
  session.notifyListeners();
}

void main() {
  test('routes a cold-start push emitted during initialization', () async {
    const id = '123e4567-e89b-42d3-a456-426614174000';
    final transport = _Transport();
    final session = SessionController(role: 'student', tokenStore: _Store());
    final api = ApiClient(
      baseUrl: Uri.parse('https://civora.test/api/v1/'),
      transport: transport,
      sessionController: session,
    );
    final push = _InitialMessagePush({
      'type': 'point_record_created',
      'resource_id': id,
    });
    final controller = NotificationController(
      api: api,
      session: session,
      push: push,
    );
    final opened = <String>[];
    controller.onPushedResource = (type, resourceId) async {
      opened.add('$type:$resourceId');
    };

    await controller.initializePush();
    await Future<void>.delayed(Duration.zero);

    expect(controller.pendingPush, {
      'type': 'point_record_created',
      'resource_id': id,
    });
    expect(controller.loginRequested, isTrue);
    expect(transport.requests, isEmpty);

    _loginAs(session, 'account-a');
    await Future<void>.delayed(Duration.zero);

    expect(controller.loginRequested, isTrue);
    expect(transport.requests, hasLength(1));
    expect(transport.requests.single.uri.path, endsWith('/notifications'));

    transport.pending.single.complete(
      ApiResponse(
        200,
        jsonEncode({
          'data': [
            {'type': 'point_record_created', 'resource_id': id},
          ],
        }),
      ),
    );
    await Future<void>.delayed(Duration.zero);
    expect(transport.requests, hasLength(2));
    expect(transport.requests.last.uri.path, endsWith('/student/records/$id'));

    transport.pending.last.complete(
      ApiResponse(
        200,
        jsonEncode({
          'data': {'id': id},
        }),
      ),
    );
    await Future<void>.delayed(Duration.zero);

    expect(opened, ['point_record_created:$id']);
    expect(controller.pendingPush, isNull);
    expect(controller.loginRequested, isFalse);
    controller.dispose();
    await push.close();
    api.close();
    session.dispose();
  });

  test('the current session can load notifications', () async {
    final transport = _Transport();
    final session = SessionController(role: 'student', tokenStore: _Store());
    final api = ApiClient(
      baseUrl: Uri.parse('https://civora.test/api/v1/'),
      transport: transport,
      sessionController: session,
    );
    _loginAs(session, 'account-a');
    final controller = NotificationController(api: api, session: session);

    final load = controller.load();
    transport.pending.single.complete(_ok('account-a-item'));
    await load;

    expect(controller.notifications.single.id, 'account-a-item');
    expect(controller.errorMessage, isNull);
    expect(controller.isLoading, isFalse);
    controller.dispose();
    api.close();
    session.dispose();
  });

  test(
    'old successes cannot replace a newer account list or loading state',
    () async {
      final transport = _Transport();
      final session = SessionController(role: 'student', tokenStore: _Store());
      final api = ApiClient(
        baseUrl: Uri.parse('https://civora.test/api/v1/'),
        transport: transport,
        sessionController: session,
      );
      _loginAs(session, 'account-a');
      final controller = NotificationController(api: api, session: session);

      final oldDuringLoad = controller.load();
      final oldAfterLoad = controller.load();
      session.end();
      _loginAs(session, 'account-b');
      final currentLoad = controller.load();

      transport.pending[0].complete(_ok('stale-item-during-load'));
      await oldDuringLoad;
      expect(controller.notifications, isEmpty);
      expect(controller.errorMessage, isNull);
      expect(controller.isLoading, isTrue);

      transport.pending[2].complete(_ok('account-b-item'));
      await currentLoad;
      transport.pending[1].complete(_ok('stale-item-after-load'));
      await oldAfterLoad;

      expect(controller.notifications.single.id, 'account-b-item');
      expect(controller.errorMessage, isNull);
      expect(controller.isLoading, isFalse);
      controller.dispose();
      api.close();
      session.dispose();
    },
  );

  test(
    'old failures cannot replace a newer account list, error, or loading state',
    () async {
      final transport = _Transport();
      final session = SessionController(role: 'student', tokenStore: _Store());
      final api = ApiClient(
        baseUrl: Uri.parse('https://civora.test/api/v1/'),
        transport: transport,
        sessionController: session,
      );
      _loginAs(session, 'account-a');
      final controller = NotificationController(api: api, session: session);

      final oldDuringLoad = expectLater(
        controller.load(),
        throwsA(isA<ApiException>()),
      );
      final oldAfterLoad = expectLater(
        controller.load(),
        throwsA(isA<ApiException>()),
      );
      session.end();
      _loginAs(session, 'account-b');
      final currentLoad = controller.load();

      transport.pending[0].complete(_failure('Old account request failed.'));
      await oldDuringLoad;
      expect(controller.notifications, isEmpty);
      expect(controller.errorMessage, isNull);
      expect(controller.isLoading, isTrue);

      transport.pending[2].complete(_ok('account-b-item'));
      await currentLoad;
      transport.pending[1].complete(_failure('Old account request failed.'));
      await oldAfterLoad;

      expect(controller.notifications.single.id, 'account-b-item');
      expect(controller.errorMessage, isNull);
      expect(controller.isLoading, isFalse);
      controller.dispose();
      api.close();
      session.dispose();
    },
  );
}

ApiResponse _failure(String message) => ApiResponse(
  500,
  jsonEncode({
    'error': {'message': message},
  }),
);
