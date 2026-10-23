import 'dart:convert';

import 'package:civora_core/civora_core.dart';
import 'package:civora_teacher/features/points/record_point_controller.dart';
import 'package:civora_teacher/features/scanner/scanner_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

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

ApiResponse _ok(Object data) => ApiResponse(200, jsonEncode({'data': data}));

void main() {
  testWidgets('camera permission denied still exposes manual student search', (
    tester,
  ) async {
    var manualSearchOpened = false;
    await tester.pumpWidget(
      MaterialApp(
        home: TeacherScannerScreen(
          cameraViewBuilder: (_) =>
              const Center(child: Text('Izin kamera ditolak.')),
          onToken: (_, _) async {},
          onManualSearch: () => manualSearchOpened = true,
        ),
      ),
    );
    expect(find.text('Izin kamera ditolak.'), findsOneWidget);
    await tester.tap(find.text('Cari siswa secara manual'));
    expect(manualSearchOpened, isTrue);
    await tester.pumpWidget(const SizedBox.shrink());
  });

  test('camera fallback leaves manual selection available', () async {
    final transport = _Transport()
      ..responses.add(
        _ok({
          'id': 'selection-1',
          'student_id': 'student-1',
          'expires_at': '2099-10-13T09:05:00Z',
        }),
      )
      ..responses.add(
        _ok({
          'id': 'student-1',
          'name': 'Alya Sari',
          'nisn': '00123',
          'semester': {'id': 'semester-1', 'name': 'Ganjil'},
          'enrollment': {
            'classroom': {'name': 'VII A'},
            'major': {'name': 'Umum'},
          },
          'totals': {'achievement': 8, 'violation': 0},
        }),
      );
    final controller = RecordPointController(
      ApiClient(
        baseUrl: Uri.parse('http://localhost/api/v1'),
        transport: transport,
      ),
    );

    final selection = await controller.selectManual('student-1');

    expect(selection.id, 'selection-1');
    expect(controller.studentProfile?['nisn'], '00123');
    expect(transport.requests.map((request) => request.uri.path), [
      '/api/v1/teacher/student-selections',
      '/api/v1/teacher/students/student-1',
    ]);
  });

  test(
    'same QR callback in flight shares one request key and selection',
    () async {
      final transport = _Transport()
        ..responses.add(
          _ok({
            'id': 'selection-qr',
            'student_id': 'student-1',
            'expires_at': '2099-10-13T09:05:00Z',
          }),
        )
        ..responses.add(
          _ok({
            'id': 'student-1',
            'name': 'Alya Sari',
            'nisn': '00123',
            'semester': {'id': 'semester-1', 'name': 'Ganjil'},
            'enrollment': {
              'classroom': {'name': 'VII A'},
              'major': {'name': 'Umum'},
            },
            'totals': {'achievement': 8, 'violation': 0},
          }),
        );
      final controller = RecordPointController(
        ApiClient(
          baseUrl: Uri.parse('http://localhost/api/v1'),
          transport: transport,
        ),
      );

      final first = controller.selectQr('civora1_secret', 'request-key-1');
      final duplicate = controller.selectQr('civora1_secret', 'request-key-1');
      expect((await first).id, (await duplicate).id);
      expect(
        transport.requests
            .where((request) => request.uri.path.endsWith('/qr/redeem'))
            .length,
        1,
      );
      expect(
        jsonDecode(transport.requests.first.body!)['request_key'],
        'request-key-1',
      );
      expect(transport.requests.first.uri.query, isEmpty);
    },
  );

  test(
    'search result identity keeps NISN and classroom to distinguish same names',
    () {
      final first = TeacherStudent.fromJson({
        'id': '1',
        'name': 'Alya Sari',
        'nisn': '0001',
        'classroom': {'id': 'a', 'name': 'VII A'},
      });
      final second = TeacherStudent.fromJson({
        'id': '2',
        'name': 'Alya Sari',
        'nisn': '0002',
        'classroom': {'id': 'b', 'name': 'VII B'},
      });
      expect(first.accessibleLabel, 'Alya Sari, NISN 0001, kelas VII A');
      expect(second.accessibleLabel, 'Alya Sari, NISN 0002, kelas VII B');
    },
  );
}
