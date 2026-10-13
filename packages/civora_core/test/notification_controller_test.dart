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
