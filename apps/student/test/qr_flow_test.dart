import 'dart:async';
import 'dart:convert';

import 'package:civora_core/civora_core.dart';
import 'package:civora_student/features/qr/qr_screen.dart';
import 'package:civora_ui/civora_ui.dart';
import 'package:flutter/material.dart';
import 'package:civora_student/features/qr/qr_controller.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:qr_flutter/qr_flutter.dart';

class _Transport implements ApiTransport {
  final requests = <ApiRequest>[];
  final responses = <Future<ApiResponse> Function()>[];

  @override
  Future<ApiResponse> send(ApiRequest request) {
    requests.add(request);
    return responses.removeAt(0)();
  }

  @override
  void close() {}
}

class _Clock {
  Duration elapsed = Duration.zero;
}

class _ManualTimer implements Timer {
  _ManualTimer(this.callback);
  final void Function() callback;
  @override
  bool isActive = true;

  @override
  void cancel() => isActive = false;
  @override
  int get tick => 0;
  void fire() {
    if (isActive) callback();
  }
}

class _Harness {
  _Harness() {
    api = ApiClient(
      baseUrl: Uri.parse('https://civora.test/api/v1'),
      transport: transport,
    );
    controller = QrController(
      api: api,
      elapsed: () => clock.elapsed,
      createTicker: (callback) {
        final timer = _ManualTimer(callback);
        timers.add(timer);
        return timer;
      },
    );
  }

  final transport = _Transport();
  final clock = _Clock();
  final timers = <_ManualTimer>[];
  late final ApiClient api;
  late final QrController controller;

  void respondWithQr(
    String token, {
    String serverTime = '2026-10-13T00:00:00Z',
  }) {
    transport.responses.add(
      () async => ApiResponse(
        200,
        jsonEncode({
          'data': {
            'token': token,
            'server_time': serverTime,
            'expires_at': DateTime.parse(
              serverTime,
            ).add(const Duration(seconds: 20)).toIso8601String(),
          },
        }),
      ),
    );
  }

  void failNext() {
    transport.responses.add(
      () async => ApiResponse(503, '{"error":{"message":"Offline"}}'),
    );
  }

  void tick() => timers.last.fire();

  void dispose() {
    controller.dispose();
    api.close();
  }
}

void main() {
  test(
    'refreshes on server-relative expiry and never exposes expired QR',
    () async {
      final harness = _Harness()..respondWithQr('qr-first');
      await harness.controller.refresh();
      expect(harness.controller.qrVisibleAsActive, isTrue);

      // The handset wall clock is irrelevant; the controller measures elapsed time.
      harness.clock.elapsed = const Duration(seconds: 19);
      harness.tick();
      expect(harness.controller.remaining, const Duration(seconds: 1));
      expect(harness.controller.qrVisibleAsActive, isTrue);

      harness.clock.elapsed = const Duration(seconds: 20);
      harness.failNext();
      harness.tick();
      await Future<void>.delayed(Duration.zero);
      expect(harness.controller.qrVisibleAsActive, isFalse);
      expect(harness.controller.qr, isNull);
      expect(harness.controller.error, isNotNull);
      harness.dispose();
    },
  );

  test('background clears QR and foreground requests a fresh one', () async {
    final harness = _Harness()..respondWithQr('qr-before-background');
    await harness.controller.refresh();
    final oldTicker = harness.timers.single;
    harness.controller.setForeground(false);
    expect(harness.controller.qr, isNull);
    expect(oldTicker.isActive, isFalse);

    harness.respondWithQr('qr-after-foreground');
    harness.controller.setForeground(true);
    await Future<void>.delayed(Duration.zero);
    expect(harness.transport.requests, hasLength(2));
    expect(harness.controller.qr?.token, 'qr-after-foreground');
    harness.dispose();
  });

  test(
    'offline refresh does not retain a prior QR and dispose cancels timer',
    () async {
      final harness = _Harness()..respondWithQr('qr-private');
      await harness.controller.refresh();
      harness.failNext();
      await expectLater(
        harness.controller.refresh(),
        throwsA(isA<ApiException>()),
      );
      expect(harness.controller.qrVisibleAsActive, isFalse);
      expect(harness.controller.qr, isNull);

      final ticker = harness.timers.last;
      harness.controller.dispose();
      expect(ticker.isActive, isFalse);
      harness.api.close();
    },
  );

  test('a late response after background cannot restore an old QR', () async {
    final harness = _Harness();
    final response = Completer<ApiResponse>();
    harness.transport.responses.add(() => response.future);
    final pending = harness.controller.refresh();
    harness.controller.setForeground(false);
    response.complete(
      ApiResponse(
        200,
        jsonEncode({
          'data': {
            'token': 'late-qr',
            'server_time': '2026-10-13T00:00:00Z',
            'expires_at': '2026-10-13T00:00:20Z',
          },
        }),
      ),
    );
    await pending;
    expect(harness.controller.qr, isNull);
    expect(harness.controller.qrVisibleAsActive, isFalse);
    harness.dispose();
  });

  testWidgets('QR is generated as black modules on white in both themes', (
    tester,
  ) async {
    for (final dark in [false, true]) {
      final harness = _Harness()..respondWithQr('server-issued-qr-token');
      await tester.pumpWidget(
        MaterialApp(
          theme: CivoraTheme.light,
          darkTheme: CivoraTheme.dark,
          themeMode: dark ? ThemeMode.dark : ThemeMode.light,
          home: StudentQrScreen(api: harness.api),
        ),
      );
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 100));
      expect(
        find.bySemanticsLabel('QR Civora untuk pemilihan siswa'),
        findsOneWidget,
      );
      final qr = tester.widget<QrImageView>(find.byType(QrImageView));
      expect(qr.backgroundColor, Colors.white);
      expect(qr.eyeStyle.color, Colors.black);
      expect(qr.dataModuleStyle.color, Colors.black);
      await tester.pumpWidget(const SizedBox.shrink());
      harness.api.close();
    }
  });
}
