import 'dart:convert';

import 'package:civora_core/civora_core.dart';
import 'package:civora_teacher/features/points/record_point_controller.dart';
import 'package:civora_teacher/features/points/record_point_screen.dart';
import 'package:civora_teacher/features/points/record_summary_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

class _Transport implements ApiTransport {
  final requests = <ApiRequest>[];
  final payloads = <String>[];
  final keys = <String>[];
  final _recordsByKey = <String, Map<String, dynamic>>{};
  int createdRecords = 0;

  @override
  Future<ApiResponse> send(ApiRequest request) async {
    requests.add(request);
    if (request.uri.path.endsWith('/teacher/student-selections')) {
      return _ok({
        'id': 'selection-1',
        'student_id': 'student-1',
        'expires_at': '2099-10-13T09:05:00Z',
      });
    }
    if (request.uri.path.endsWith('/teacher/students/student-1')) {
      return _ok({
        'id': 'student-1',
        'name': 'Alya Sari',
        'nisn': '00123',
        'semester': {'id': 'semester-1', 'name': 'Ganjil'},
        'enrollment': {
          'classroom': {'name': 'VII A'},
          'major': {'name': 'Umum'},
        },
        'totals': {'achievement': 8, 'violation': 0},
      });
    }
    if (request.uri.path.endsWith('/teacher/point-types')) {
      return ApiResponse(
        200,
        jsonEncode({
          'data': [
            {
              'version_id': 'version-1',
              'name': 'Disiplin',
              'category': 'achievement',
              'points': 2,
            },
            {
              'version_id': 'version-2',
              'name': 'Kerja sama',
              'category': 'achievement',
              'points': 3,
            },
          ],
        }),
      );
    }
    if (request.method == 'GET' &&
        request.uri.path.contains('/teacher/record-attempts/')) {
      return ApiResponse(
        404,
        jsonEncode({
          'error': {'code': 'NOT_FOUND', 'message': 'Belum tersedia.'},
        }),
      );
    }
    if (request.method == 'POST' &&
        request.uri.path.endsWith('/teacher/records')) {
      final key = request.headers['Idempotency-Key']!;
      keys.add(key);
      payloads.add(request.body!);
      final record = _recordsByKey[key];
      if (record != null) return _ok(record);
      createdRecords++;
      _recordsByKey[key] = const {
        'id': 'record-1',
        'source': 'manual',
        'occurred_at': '2026-10-13T08:00:00Z',
        'student': {'id': 'student-1', 'name': 'Alya Sari'},
        'teacher': {'id': 'teacher-1', 'name': 'Guru Uji'},
        'semester': {'id': 'semester-1', 'name': 'Ganjil'},
      };
      throw const FormatException('save response lost');
    }
    throw StateError('Unexpected ${request.method} ${request.uri.path}');
  }

  @override
  void close() {}
}

ApiResponse _ok(Map<String, dynamic> data) =>
    ApiResponse(200, jsonEncode({'data': data}));

void main() {
  testWidgets(
    'returning to a frozen form cannot edit it and same-key retry still resolves',
    (tester) async {
      final transport = _Transport();
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
      expect(controller.hasUnknownAttempt, isTrue);

      await tester.pumpWidget(
        MaterialApp(
          home: Builder(
            builder: (context) => Scaffold(
              body: Center(
                child: TextButton(
                  onPressed: () => Navigator.push<void>(
                    context,
                    MaterialPageRoute<void>(
                      builder: (_) => TeacherRecordPointScreen(
                        api: api,
                        controller: controller,
                      ),
                    ),
                  ),
                  child: const Text('Open frozen form'),
                ),
              ),
            ),
          ),
        ),
      );
      await tester.tap(find.text('Open frozen form'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Periksa upaya'));
      await tester.pumpAndSettle();
      expect(
        find.textContaining('Hasil simpan belum diketahui'),
        findsOneWidget,
      );

      await tester.pageBack();
      await tester.pumpAndSettle();
      final selector = tester.widget<DropdownButtonFormField<String>>(
        find.byType(DropdownButtonFormField<String>),
      );
      expect(selector.onChanged, isNull);
      expect(tester.widget<TextField>(find.byType(TextField)).readOnly, isTrue);
      expect(
        () => controller.selectPointType('version-2'),
        throwsA(isA<StateError>()),
      );
      expect(
        () => controller.description = 'Ganti isi setelah kembali.',
        throwsA(isA<StateError>()),
      );
      expect(() => controller.removePhoto(), throwsA(isA<StateError>()));
      expect(controller.hasUnknownAttempt, isTrue);

      await tester.tap(find.text('Periksa upaya'));
      await tester.pumpAndSettle();
      expect(find.byType(RecordSummaryScreen), findsOneWidget);
      await tester.drag(find.byType(ListView).last, const Offset(0, -700));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Kirim ulang dengan kunci yang sama'));
      await tester.pumpAndSettle();

      expect(find.text('Poin berhasil dicatat'), findsOneWidget);
      expect(transport.createdRecords, 1);
      expect(transport.keys, hasLength(2));
      expect(transport.keys[0], transport.keys[1]);
      expect(transport.payloads[0], transport.payloads[1]);
      expect(controller.hasUnknownAttempt, isFalse);
      api.close();
    },
  );
}
