import 'dart:async';
import 'dart:convert';

import 'package:civora_core/civora_core.dart';
import 'package:civora_ui/civora_ui.dart';
import 'package:civora_teacher/features/home/home_screen.dart';
import 'package:civora_teacher/features/points/record_point_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

class _Transport implements ApiTransport {
  _Transport({this.commitThenLoseFirstSave = false});
  final bool commitThenLoseFirstSave;
  final requests = <ApiRequest>[];
  final responses = <Object>[];
  final recordsByKey = <String, Map<String, dynamic>>{};
  final sentIdempotencyKeys = <String>[];
  Completer<ApiResponse>? heldRetry;
  Completer<ApiResponse>? heldStudentSelection;
  int createdRecordCount = 0;
  @override
  Future<ApiResponse> send(ApiRequest request) async {
    requests.add(request);
    if (request.method == 'POST' &&
        request.uri.path.endsWith('/teacher/student-selections') &&
        (jsonDecode(request.body!) as Map<String, dynamic>)['student_id'] ==
            'student-2') {
      return heldStudentSelection!.future;
    }
    if (commitThenLoseFirstSave &&
        request.method == 'POST' &&
        request.uri.path.endsWith('/teacher/records')) {
      final key = request.headers['Idempotency-Key']!;
      sentIdempotencyKeys.add(key);
      if (recordsByKey.containsKey(key)) {
        final pending = heldRetry;
        if (pending != null) return pending.future;
        return _ok(recordsByKey[key]!);
      }
      createdRecordCount++;
      recordsByKey[key] = _record;
      throw const FormatException('response lost after server commit');
    }
    final response = responses.removeAt(0);
    if (response is Exception) throw response;
    return response as ApiResponse;
  }

  @override
  void close() {}
}

class _Store implements SessionTokenStore {
  @override
  Future<void> delete(String key) async {}

  @override
  Future<String?> read(String key) async => null;

  @override
  Future<void> write(String key, String token) async {}
}

ApiResponse _ok(Map<String, dynamic> data) =>
    ApiResponse(200, jsonEncode({'data': data}));
const _student = {
  'id': 'student-1',
  'name': 'Alya Sari',
  'nisn': '00123',
  'semester': {'id': 'semester-1', 'name': 'Ganjil'},
  'enrollment': {
    'classroom': {'name': 'VII A'},
    'major': {'name': 'Umum'},
  },
  'totals': {'achievement': 8, 'violation': 0},
};
const _record = {
  'id': 'record-1',
  'source': 'manual',
  'occurred_at': '2026-10-13T08:00:00Z',
  'student': {'id': 'student-1', 'name': 'Alya Sari'},
  'teacher': {'id': 'teacher-1', 'name': 'Guru'},
  'semester': {'id': 'semester-1', 'name': 'Ganjil'},
};

void main() {
  test(
    'late manual selection cannot replace a save that became unknown',
    () async {
      final transport = _Transport(commitThenLoseFirstSave: true)
        ..heldStudentSelection = Completer<ApiResponse>()
        ..responses.addAll([
          _ok({
            'id': 'selection-1',
            'student_id': 'student-1',
            'expires_at': '2099-10-13T09:05:00Z',
          }),
          _ok(_student),
          ApiResponse(
            200,
            jsonEncode({
              'data': [
                {
                  'version_id': 'version-1',
                  'name': 'Disiplin',
                  'category': 'achievement',
                  'points': 2,
                },
              ],
            }),
          ),
          ApiResponse(
            200,
            jsonEncode({
              'data': {
                ..._record,
                'student': {'id': 'student-1', 'name': 'Alya Sari'},
              },
            }),
          ),
          _ok(_student),
        ]);
      final controller = RecordPointController(
        ApiClient(
          baseUrl: Uri.parse('http://localhost/api/v1'),
          transport: transport,
        ),
      );
      await controller.selectManual('student-1');
      await controller.loadPointTypes();
      controller.selectPointType('version-1');
      controller.description = 'Membantu merapikan kelas.';

      final pendingSelection = controller.selectManual('student-2');
      await expectLater(controller.submit(), throwsA(isA<FormatException>()));
      expect(controller.hasUnknownAttempt, isTrue);
      final originalSave = transport.requests.singleWhere(
        (request) => request.uri.path.endsWith('/teacher/records'),
      );
      final frozenKey = originalSave.headers['Idempotency-Key'];
      final frozenPayload = originalSave.body;

      transport.heldStudentSelection!.complete(
        _ok({
          'id': 'selection-2',
          'student_id': 'student-2',
          'expires_at': '2099-10-13T09:05:00Z',
        }),
      );
      await expectLater(pendingSelection, throwsA(isA<StateError>()));

      expect(controller.hasUnknownAttempt, isTrue);
      expect(controller.selection!.id, 'selection-1');
      expect(controller.selection!.studentId, 'student-1');
      expect(
        () => controller.selectPointType('version-1'),
        throwsA(isA<StateError>()),
      );

      final saved = await controller.retryUnknownAttempt();
      expect(saved.id, 'record-1');
      final posts = transport.requests
          .where((request) => request.uri.path.endsWith('/teacher/records'))
          .toList();
      expect(posts, hasLength(2));
      expect(posts[1].headers['Idempotency-Key'], frozenKey);
      expect(posts[1].body, frozenPayload);
      expect(transport.createdRecordCount, 1);
      expect(controller.hasUnknownAttempt, isFalse);
    },
  );

  test(
    'lost save response retries identical frozen payload and key then resolves once',
    () async {
      final transport = _Transport(commitThenLoseFirstSave: true)
        ..responses.add(
          _ok({
            'id': 'selection-1',
            'student_id': 'student-1',
            'expires_at': '2099-10-13T09:05:00Z',
          }),
        )
        ..responses.add(_ok(_student))
        ..responses.add(
          ApiResponse(
            200,
            jsonEncode({
              'data': [
                {
                  'version_id': 'version-1',
                  'name': 'Disiplin',
                  'category': 'achievement',
                  'points': 2,
                },
              ],
            }),
          ),
        )
        ..responses.add(
          ApiResponse(
            404,
            jsonEncode({
              'error': {'code': 'NOT_FOUND', 'message': 'Belum tersedia.'},
            }),
          ),
        )
        ..responses.add(_ok(_student));
      final controller = RecordPointController(
        ApiClient(
          baseUrl: Uri.parse('http://localhost/api/v1'),
          transport: transport,
        ),
      );
      await controller.selectManual('student-1');
      await controller.loadPointTypes();
      controller.selectPointType('version-1');
      controller.description = 'Membantu merapikan kelas.';

      await expectLater(controller.submit(), throwsA(isA<FormatException>()));
      expect(controller.hasUnknownAttempt, isTrue);
      expect(await controller.resolveUnknownAttempt(), isNull);
      expect(
        () => controller.selectPointType('version-1'),
        throwsA(isA<StateError>()),
      );
      expect(controller.hasUnknownAttempt, isTrue);
      await expectLater(
        controller.selectManual('student-2'),
        throwsA(isA<StateError>()),
      );
      expect(controller.selection!.studentId, 'student-1');
      expect(transport.requests, hasLength(5));
      final heldRetry = Completer<ApiResponse>();
      transport.heldRetry = heldRetry;
      final retry = controller.retryUnknownAttempt();
      await Future<void>.delayed(Duration.zero);
      expect(controller.hasUnknownAttempt, isTrue);
      await expectLater(
        controller.selectManual('student-2'),
        throwsA(isA<StateError>()),
      );
      heldRetry.complete(_ok(_record));
      final saved = await retry;

      expect(saved.id, 'record-1');
      final posts = transport.requests
          .where((request) => request.uri.path.endsWith('/teacher/records'))
          .toList();
      expect(posts, hasLength(2));
      expect(
        posts.map((request) => request.headers['Idempotency-Key']).toSet(),
        hasLength(1),
      );
      expect(posts.map((request) => request.body).toSet(), hasLength(1));
      expect(transport.createdRecordCount, 1);
      expect(transport.sentIdempotencyKeys.toSet(), hasLength(1));
      expect(controller.hasUnknownAttempt, isFalse);
    },
  );

  testWidgets(
    'unknown attempt stays visible across tabs and blocks starting another record',
    (tester) async {
      final transport = _Transport(commitThenLoseFirstSave: true)
        ..responses.add(
          _ok({
            'id': 'selection-1',
            'student_id': 'student-1',
            'expires_at': '2099-10-13T09:05:00Z',
          }),
        )
        ..responses.add(_ok(_student))
        ..responses.add(
          ApiResponse(
            200,
            jsonEncode({
              'data': [
                {
                  'version_id': 'version-1',
                  'name': 'Disiplin',
                  'category': 'achievement',
                  'points': 2,
                },
              ],
            }),
          ),
        )
        ..responses.add(
          _ok({
            'id': 'record-1',
            'source': 'manual',
            'occurred_at': '2026-10-13T08:00:00Z',
            'student': {'id': 'student-1', 'name': 'Alya Sari'},
            'teacher': {'id': 'teacher-1', 'name': 'Guru'},
            'semester': {'id': 'semester-1', 'name': 'Ganjil'},
          }),
        )
        ..responses.addAll([
          ApiResponse(200, jsonEncode({'data': []})),
          ApiResponse(200, jsonEncode({'data': []})),
          ApiResponse(200, jsonEncode({'data': []})),
        ]);
      final api = ApiClient(
        baseUrl: Uri.parse('http://localhost/api/v1'),
        transport: transport,
      );
      final controller = RecordPointController(api);
      await controller.selectManual('student-1');
      await controller.loadPointTypes();
      controller.selectPointType('version-1');
      controller.description = 'Membantu merapikan kelas.';
      await expectLater(controller.submit(), throwsA(isA<FormatException>()));

      final session = SessionController(role: 'teacher', tokenStore: _Store())
        ..state = SessionState.authenticated
        ..profile = const MeDto(
          account: AccountDto(
            id: 'teacher-1',
            name: 'Guru Uji',
            role: 'teacher',
            accountStatus: 'active',
            mustChangePassword: false,
          ),
        );
      session.bindApi(api);
      await tester.pumpWidget(
        MaterialApp(
          home: TeacherHomeScreen(
            api: api,
            session: session,
            notifications: NotificationController(api: api, session: session),
            themePreference: ThemePreferenceController(),
            recordController: controller,
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(
        find.textContaining('Hasil simpan catatan belum diketahui'),
        findsOneWidget,
      );
      await tester.tap(find.text('Siswa'));
      await tester.pumpAndSettle();
      expect(
        find.textContaining('Hasil simpan catatan belum diketahui'),
        findsOneWidget,
      );
      final requestCount = transport.requests.length;
      await expectLater(
        controller.selectManual('student-2'),
        throwsA(isA<StateError>()),
      );
      expect(transport.requests, hasLength(requestCount));
      expect(controller.selection!.studentId, 'student-1');

      await tester.tap(find.text('Periksa upaya'));
      await tester.pumpAndSettle();
      expect(find.text('Alya Sari'), findsOneWidget);
      expect(find.text('Keterangan'), findsOneWidget);
      api.close();
      session.dispose();
    },
  );

  test(
    'selection expiry blocks submission and requires a new choice',
    () async {
      final transport = _Transport()
        ..responses.add(
          _ok({
            'id': 'selection-1',
            'student_id': 'student-1',
            'expires_at': '2020-01-01T00:00:00Z',
          }),
        )
        ..responses.add(_ok(_student));
      final controller = RecordPointController(
        ApiClient(
          baseUrl: Uri.parse('http://localhost/api/v1'),
          transport: transport,
        ),
      );
      await controller.selectManual('student-1');
      expect(controller.selectionExpired, isTrue);
      expect(controller.requiresStudentReselection, isTrue);
    },
  );

  test(
    'failed totals refresh does not turn acknowledged save into unknown',
    () async {
      final transport = _Transport()
        ..responses.addAll([
          _ok({
            'id': 'selection-1',
            'student_id': 'student-1',
            'expires_at': '2099-10-13T09:05:00Z',
          }),
          _ok(_student),
          ApiResponse(
            200,
            jsonEncode({
              'data': [
                {
                  'version_id': 'version-1',
                  'name': 'Disiplin',
                  'category': 'achievement',
                  'points': 2,
                },
              ],
            }),
          ),
          _ok(_record),
          const FormatException('totals refresh lost'),
        ]);
      final controller = RecordPointController(
        ApiClient(
          baseUrl: Uri.parse('http://localhost/api/v1'),
          transport: transport,
        ),
      );
      await controller.selectManual('student-1');
      await controller.loadPointTypes();
      controller.selectPointType('version-1');
      controller.description = 'Membantu merapikan kelas.';

      final record = await controller.submit();

      expect(record.id, 'record-1');
      expect(controller.hasUnknownAttempt, isFalse);
      expect(controller.savedRecord?.id, 'record-1');
    },
  );

  test(
    'catalog conflict reloads version and keeps description for renewed summary',
    () async {
      final transport = _Transport()
        ..responses.add(
          _ok({
            'id': 'selection-1',
            'student_id': 'student-1',
            'expires_at': '2099-10-13T09:05:00Z',
          }),
        )
        ..responses.add(_ok(_student))
        ..responses.add(
          ApiResponse(
            200,
            jsonEncode({
              'data': [
                {
                  'version_id': 'version-old',
                  'name': 'Disiplin',
                  'category': 'achievement',
                  'points': 2,
                },
              ],
            }),
          ),
        )
        ..responses.add(
          ApiResponse(
            409,
            jsonEncode({
              'error': {
                'code': 'POINT_TYPE_CHANGED',
                'message': 'Jenis poin berubah.',
              },
            }),
          ),
        )
        ..responses.add(
          ApiResponse(
            200,
            jsonEncode({
              'data': [
                {
                  'version_id': 'version-new',
                  'name': 'Disiplin diperbarui',
                  'category': 'achievement',
                  'points': 3,
                },
              ],
            }),
          ),
        );
      final controller = RecordPointController(
        ApiClient(
          baseUrl: Uri.parse('http://localhost/api/v1'),
          transport: transport,
        ),
      );
      await controller.selectManual('student-1');
      await controller.loadPointTypes();
      controller.selectPointType('version-old');
      controller.description =
          'Keterangan tetap ada setelah perubahan katalog.';

      await expectLater(
        controller.submit(),
        throwsA(
          isA<ApiException>().having(
            (error) => error.code,
            'code',
            'POINT_TYPE_CHANGED',
          ),
        ),
      );

      expect(controller.requiresReconfirmation, isTrue);
      expect(
        controller.description,
        'Keterangan tetap ada setelah perubahan katalog.',
      );
      expect(controller.selectedPointType, isNull);
      expect(controller.pointTypes.single.versionId, 'version-new');
    },
  );

  test('photo over five MiB is rejected before upload', () async {
    final transport = _Transport();
    expect(
      () => RecordPointController.validatePhoto(
        bytes: List<int>.filled(5 * 1024 * 1024 + 1, 0),
        filename: 'proof.jpg',
        mimeType: 'image/jpeg',
      ),
      throwsArgumentError,
    );
    expect(transport.requests, isEmpty);
  });
}
