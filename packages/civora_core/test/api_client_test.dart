import 'dart:convert';

import 'package:civora_core/civora_core.dart';
import 'package:flutter_test/flutter_test.dart';

class RecordingTransport implements ApiTransport {
  final responses = <ApiResponse>[];
  final requests = <ApiRequest>[];

  @override
  Future<ApiResponse> send(ApiRequest request) async {
    requests.add(request);
    return responses.removeAt(0);
  }

  @override
  void close() {}
}

class _TokenStore implements SessionTokenStore {
  final values = <String, String>{};
  @override
  Future<String?> read(String key) async => values[key];
  @override
  Future<void> write(String key, String token) async => values[key] = token;
  @override
  Future<void> delete(String key) async => values.remove(key);
}

void main() {
  group('ApiClient and DTOs', () {
    test('maps summary snake_case and preserves null as unavailable', () {
      final active = SummaryDto.fromJson({
        'semester': {'id': 'semester-1', 'name': 'Ganjil'},
        'totals': {'achievement': 10, 'violation': 5},
        'latest_threshold_status': null,
      });
      final inactive = SummaryDto.fromJson({
        'semester': null,
        'totals': null,
        'latest_threshold_status': null,
      });

      expect(active.achievement, 10);
      expect(active.violation, 5);
      expect(active.semester?.id, 'semester-1');
      expect(inactive.achievement, isNull);
      expect(inactive.violation, isNull);
    });

    test('maps raw selection and optional enriched student exactly', () {
      final raw = SelectionDto.fromJson({
        'id': 'selection-1',
        'student_id': 'student-1',
        'expires_at': '2026-10-13T08:00:00+00:00',
      });
      final enriched = SelectionDto.fromJson({
        'id': 'selection-2',
        'student_id': 'student-2',
        'expires_at': '2026-10-13T08:00:00Z',
        'student': {'id': 'student-2', 'nisn': '0002', 'education_status': 'active'},
      });

      expect(raw.studentId, 'student-1');
      expect(raw.student, isNull);
      expect(raw.expiresAt.isUtc, isTrue);
      expect(enriched.student?.nisn, '0002');
    });

    test('preserves structured conflict details', () async {
      final transport = RecordingTransport()
        ..responses.add(ApiResponse(409, jsonEncode({
          'error': {
            'code': 'IDEMPOTENCY_CONFLICT',
            'message': 'Kunci sudah digunakan.',
            'fields': {'key': ['Gunakan kunci baru.']},
          },
          'request_id': 'request-1',
        })));
      final client = ApiClient(baseUrl: Uri.parse('http://localhost/api/v1'), transport: transport);

      await expectLater(
        client.post('/teacher/records', const {}),
        throwsA(isA<ApiException>().having((e) => e.statusCode, 'statusCode', 409)
          .having((e) => e.code, 'code', 'IDEMPOTENCY_CONFLICT')
          .having((e) => e.message, 'message', 'Kunci sudah digunakan.')
          .having((e) => e.fields['key'], 'fields', ['Gunakan kunci baru.'])
          .having((e) => e.requestId, 'requestId', 'request-1')),
      );
    });

    test('login never sends a prior bearer token', () async {
      final transport = RecordingTransport()
        ..responses.add(ApiResponse(200, jsonEncode({'data': {'token': 'new'}})));
      final session = SessionController(role: 'teacher', tokenStore: _TokenStore())
        ..bearerToken = 'old-token';
      final client = ApiClient(
        baseUrl: Uri.parse('http://localhost/api/v1'),
        transport: transport,
        sessionController: session,
      );

      await client.post('/auth/student/login', const {'nisn': '001', 'password': 'secret'});

      expect(transport.requests.single.headers, isNot(contains('Authorization')));
      expect(session.bearerToken, 'old-token');
    });

    test('401 ends the current session', () async {
      final transport = RecordingTransport()
        ..responses.add(ApiResponse(401, jsonEncode({'error': {'code': 'INVALID_CREDENTIALS', 'message': 'Sesi habis.'}})));
      final store = _TokenStore()..values['civora.student.token'] = 'token';
      final session = SessionController(role: 'student', tokenStore: store)
        ..bearerToken = 'token'
        ..state = SessionState.authenticated;
      final client = ApiClient(
        baseUrl: Uri.parse('http://localhost/api/v1'),
        transport: transport,
        sessionController: session,
      );

      await expectLater(client.get('/me'), throwsA(isA<ApiException>()));
      expect(session.state, SessionState.unauthenticated);
      expect(session.bearerToken, isNull);
      expect(store.values, isEmpty);
    });
    test('keeps pending profile minimal and parses nullable teacher identity', () {
      final pending = MeDto.fromJson({
        'id': 'user-1', 'name': 'Siswa', 'role': 'student',
        'account_status': 'pending_activation', 'must_change_password': true,
      });
      final teacher = MeDto.fromJson({
        'id': 'user-2', 'name': 'Guru', 'role': 'teacher',
        'account_status': 'active', 'must_change_password': false,
        'teacher': {'id': 'teacher-2', 'nip': null, 'teacher_code': 'G-002'},
        'current_semester': {'id': 'semester-1', 'name': 'Ganjil'},
      });
      expect(pending.student, isNull);
      expect(pending.currentSemester, isNull);
      expect(pending.account.mustChangePassword, isTrue);
      expect(teacher.teacher?.nip, isNull);
      expect(teacher.currentSemester?.id, 'semester-1');
    });
  });
}
