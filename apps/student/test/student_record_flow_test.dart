import 'dart:convert';
import 'dart:typed_data';

import 'package:civora_core/civora_core.dart';
import 'package:civora_student/features/home/home_screen.dart';
import 'package:civora_student/features/records/record_detail_screen.dart';
import 'package:civora_student/features/records/records_screen.dart';
import 'package:civora_student/features/reviews/objection_screen.dart';
import 'package:civora_ui/civora_ui.dart';
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

ApiResponse _json(Object value) => ApiResponse(200, jsonEncode(value));

MeDto _profile({String? schoolTimezone = 'Asia/Jakarta'}) => MeDto.fromJson({
  'id': 'user-1',
  'name': 'Siswa Uji',
  'role': 'student',
  'account_status': 'active',
  'must_change_password': false,
  'student': {
    'id': 'student-1',
    'nisn': '000123',
    'education_status': 'active',
  },
  'current_semester': {'id': 'semester-current', 'name': 'Ganjil 2026/2027'},
  'school_timezone': schoolTimezone,
  'own_enrollment': {
    'id': 'enrollment-1',
    'classroom': {'id': 'class-1', 'name': 'X-1'},
    'major': {
      'id': 'major-1',
      'name': 'Rekayasa Perangkat Lunak',
      'code': 'RPL',
    },
  },
});

Map<String, dynamic> _record({
  String status = 'active',
  String? reviewStatus,
}) => {
  'id': 'record-own',
  'source': 'qr',
  'occurred_at': '2026-10-13T00:00:00Z',
  'student': {'id': 'student-1', 'name': 'Siswa Uji'},
  'teacher': {'id': 'teacher-1', 'name': 'Guru Uji'},
  'semester': {'id': 'semester-current', 'name': 'Ganjil 2026/2027'},
  'latest_revision': {
    'revision_number': 2,
    'name': 'Membantu kegiatan sekolah',
    'category': 'achievement',
    'points': 10,
    'description': 'Membantu kegiatan sekolah.',
    'attachment_id': 'private-photo-1',
    'status': status,
  },
  'review_status': reviewStatus,
};

void main() {
  testWidgets('home remains usable at 2x text scale on a small screen', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(360, 640);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    for (final theme in [CivoraTheme.light, CivoraTheme.dark]) {
      final transport = _Transport()
        ..responses.add(
          _json({
            'data': {
              'semester': {
                'id': 'semester-current',
                'name': 'Ganjil 2026/2027',
              },
              'totals': {'achievement': 10, 'violation': 5},
              'latest_threshold_status': null,
            },
          }),
        )
        ..responses.add(
          _json({
            'data': [],
            'meta': {'current_page': 1, 'last_page': 1},
          }),
        );
      final api = ApiClient(
        baseUrl: Uri.parse('https://civora.test/api/v1'),
        transport: transport,
      );
      var qrOpened = false;

      await tester.pumpWidget(
        MaterialApp(
          theme: theme,
          builder: (context, child) => MediaQuery(
            data: MediaQuery.of(
              context,
            ).copyWith(textScaler: TextScaler.linear(2)),
            child: child!,
          ),
          home: Scaffold(
            body: StudentHomeScreen(
              api: api,
              profile: _profile(),
              onOpenQr: () => qrOpened = true,
              onOpenRecord: (_) {},
              onOpenHistory: () {},
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
      expect(find.text('Poin semester ini'), findsOneWidget);
      expect(find.text('Prestasi'), findsOneWidget);
      expect(find.text('Pelanggaran'), findsOneWidget);
      final qrAction = find.widgetWithText(FilledButton, 'Tampilkan QR');
      await tester.drag(find.byType(ListView).first, const Offset(0, -500));
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
      await tester.tap(qrAction);
      expect(qrOpened, isTrue);
      expect(tester.takeException(), isNull);
      api.close();
    }
  });

  testWidgets(
    'home displays separate server totals and enrollment, without inventing zero',
    (tester) async {
      final transport = _Transport()
        ..responses.add(
          _json({
            'data': {
              'semester': {
                'id': 'semester-current',
                'name': 'Ganjil 2026/2027',
              },
              'totals': {'achievement': 10, 'violation': 5},
              'latest_threshold_status': null,
            },
          }),
        )
        ..responses.add(
          _json({
            'data': [],
            'meta': {'current_page': 1, 'last_page': 1},
          }),
        );
      final api = ApiClient(
        baseUrl: Uri.parse('https://civora.test/api/v1'),
        transport: transport,
      );
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: StudentHomeScreen(
              api: api,
              profile: _profile(),
              onOpenQr: () {},
              onOpenRecord: (_) {},
              onOpenHistory: () {},
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('10'), findsOneWidget);
      expect(find.text('5'), findsOneWidget);
      expect(find.text('Prestasi'), findsOneWidget);
      expect(find.text('Pelanggaran'), findsOneWidget);
      expect(find.text('X-1 · Rekayasa Perangkat Lunak'), findsOneWidget);
      api.close();

      final unavailableTransport = _Transport()
        ..responses.add(
          _json({
            'data': {
              'semester': null,
              'totals': null,
              'latest_threshold_status': null,
            },
          }),
        )
        ..responses.add(
          _json({
            'data': [],
            'meta': {'current_page': 1, 'last_page': 1},
          }),
        );
      final unavailableApi = ApiClient(
        baseUrl: Uri.parse('https://civora.test/api/v1'),
        transport: unavailableTransport,
      );
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: StudentHomeScreen(
              key: const ValueKey('unavailable'),
              api: unavailableApi,
              profile: _profile(),
              onOpenQr: () {},
              onOpenRecord: (_) {},
              onOpenHistory: () {},
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('—'), findsNWidgets(2));
      expect(find.text('0'), findsNothing);
      expect(
        find.textContaining('semester aktif belum ditetapkan'),
        findsOneWidget,
      );
      unavailableApi.close();
    },
  );

  testWidgets('history uses only server offered semesters as query filters', (
    tester,
  ) async {
    final transport = _Transport()
      ..responses.add(
        _json({
          'data': [_record()],
          'meta': {
            'current_page': 1,
            'last_page': 1,
            'total': 1,
            'available_semesters': [
              {
                'id': 'semester-old',
                'name': 'Genap 2025/2026',
                'start_date': '2026-01-01',
                'end_date': '2026-06-30',
                'is_current': false,
              },
            ],
          },
        }),
      )
      ..responses.add(
        _json({
          'data': [],
          'meta': {
            'current_page': 1,
            'last_page': 1,
            'total': 0,
            'available_semesters': [
              {
                'id': 'semester-old',
                'name': 'Genap 2025/2026',
                'start_date': '2026-01-01',
                'end_date': '2026-06-30',
                'is_current': false,
              },
            ],
          },
        }),
      );
    final api = ApiClient(
      baseUrl: Uri.parse('https://civora.test/api/v1'),
      transport: transport,
    );
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: StudentRecordsScreen(
            api: api,
            schoolTimezone: 'Asia/Jakarta',
            onOpenRecord: (_) {},
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Membantu kegiatan sekolah'), findsOneWidget);
    await tester.tap(find.byType(DropdownButtonFormField<String?>));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Genap 2025/2026').last);
    await tester.pumpAndSettle();
    expect(
      transport.requests.last.uri.queryParameters['semester_id'],
      'semester-old',
    );
    expect(transport.requests.last.uri.queryParameters['page'], '1');
    expect(find.text('Belum ada catatan untuk filter ini.'), findsOneWidget);
    api.close();
  });

  testWidgets('detail downloads private evidence through its bearer session', (
    tester,
  ) async {
    final transport = _Transport()
      ..responses.add(_json({'data': _record()}))
      ..responses.add(
        ApiResponse(
          200,
          '',
          bytes: Uint8List.fromList(
            base64Decode(
              'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/ZL8AAAAASUVORK5CYII=',
            ),
          ),
        ),
      );
    final session =
        SessionController(role: 'student', tokenStore: _MemoryStore())
          ..bearerToken = 'own-student-token'
          ..state = SessionState.authenticated;
    final api = ApiClient(
      baseUrl: Uri.parse('https://civora.test/api/v1'),
      transport: transport,
      sessionController: session,
    );
    await tester.pumpWidget(
      MaterialApp(
        home: StudentRecordDetailScreen(
          api: api,
          recordId: 'record-own',
          schoolTimezone: 'Asia/Jakarta',
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Membantu kegiatan sekolah'), findsOneWidget);
    expect(find.bySemanticsLabel('Foto bukti privat'), findsOneWidget);
    final photoRequest = transport.requests.last;
    expect(photoRequest.uri.path, '/api/v1/attachments/private-photo-1');
    expect(photoRequest.headers['Authorization'], 'Bearer own-student-token');
    api.close();
    session.dispose();
  });

  testWidgets('pending objection sends only reason and leaves poin effective', (
    tester,
  ) async {
    final transport = _Transport()
      ..responses.add(
        _json({
          'data': {
            'id': 'review-1',
            'record_id': 'record-own',
            'kind': 'student_objection',
            'reason': 'Mohon periksa ulang.',
            'proposed_change': null,
            'status': 'pending',
            'reviewed_revision': 2,
            'created_at': '2026-10-13T00:00:00Z',
          },
        }),
      );
    final api = ApiClient(
      baseUrl: Uri.parse('https://civora.test/api/v1'),
      transport: transport,
    );
    await tester.pumpWidget(
      MaterialApp(
        home: StudentObjectionScreen(
          api: api,
          record: PointRecordDto.fromJson(_record(reviewStatus: null)),
        ),
      ),
    );
    await tester.enterText(find.byType(TextFormField), 'Mohon periksa ulang.');
    await tester.tap(find.text('Kirim keberatan'));
    await tester.pumpAndSettle();
    expect(
      find.textContaining('Poin tetap berlaku sampai admin memutuskan'),
      findsOneWidget,
    );
    expect(
      jsonDecode(transport.requests.single.body!) as Map<String, dynamic>,
      {'reason': 'Mohon periksa ulang.'},
    );
    api.close();
  });
}

class _MemoryStore implements SessionTokenStore {
  @override
  Future<String?> read(String key) async => null;
  @override
  Future<void> write(String key, String token) async {}
  @override
  Future<void> delete(String key) async {}
}
