import 'dart:convert';
import 'dart:async';
import 'dart:typed_data';

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

class _DelayedTransport implements ApiTransport {
  _DelayedTransport(this.response);
  final Completer<ApiResponse> response;
  final requestSent = Completer<ApiRequest>();
  @override
  Future<ApiResponse> send(ApiRequest request) {
    requestSent.complete(request);
    return response.future;
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
    test('omits null query parameters', () async {
      final transport = RecordingTransport()
        ..responses.add(ApiResponse(200, jsonEncode({'data': []})));
      final client = ApiClient(
        baseUrl: Uri.parse('http://localhost/api/v1'),
        transport: transport,
      );

      await client.get('/teacher/students', query: {
        'q': '9000000001',
        'classroom_id': null,
        'major_id': null,
        'per_page': 50,
      });

      expect(transport.requests.single.uri.queryParameters, {
        'q': '9000000001',
        'per_page': '50',
      });
    });

    test(
      'multipart attachment upload uses authenticated transport and preserves bytes',
      () async {
        final bytes = Uint8List.fromList([0xff, 0xd8, 0x00, 0x80]);
        final transport = RecordingTransport()
          ..responses.add(
            ApiResponse(
              201,
              jsonEncode({
                'data': {'id': 'attachment-1'},
              }),
            ),
          );
        final session = SessionController(
          role: 'teacher',
          tokenStore: _TokenStore(),
        )..bearerToken = 'teacher-session';
        final client = ApiClient(
          baseUrl: Uri.parse('http://localhost/api/v1'),
          transport: transport,
          sessionController: session,
        );

        final response = await client.uploadFile(
          '/teacher/attachments',
          bytes: bytes,
          filename: 'proof.jpg',
          mimeType: 'image/jpeg',
        );

        final request = transport.requests.single;
        expect(response['id'], 'attachment-1');
        expect(request.headers['Authorization'], 'Bearer teacher-session');
        expect(
          request.headers['Content-Type'],
          startsWith('multipart/form-data; boundary='),
        );
        expect(request.bodyBytes!.indexOf(0xff), greaterThan(0));
        expect(
          request.bodyBytes!.sublist(
            request.bodyBytes!.indexOf(0xff),
            request.bodyBytes!.indexOf(0xff) + bytes.length,
          ),
          bytes,
        );
        expect(
          utf8.decode(
            request.bodyBytes!.sublist(0, request.bodyBytes!.indexOf(0xff)),
          ),
          contains('name="file"; filename="proof.jpg"'),
        );
      },
    );

    test('multipart upload preserves structured validation errors', () async {
      final transport = RecordingTransport()
        ..responses.add(
          ApiResponse(
            422,
            jsonEncode({
              'error': {
                'code': 'VALIDATION_ERROR',
                'message': 'Bukti tidak valid.',
                'fields': {
                  'file': ['Format tidak didukung.'],
                },
              },
              'request_id': 'upload-1',
            }),
          ),
        );
      final client = ApiClient(
        baseUrl: Uri.parse('http://localhost/api/v1'),
        transport: transport,
      );
      await expectLater(
        client.uploadFile(
          '/teacher/attachments',
          bytes: Uint8List.fromList([1]),
          filename: 'proof.jpg',
          mimeType: 'image/jpeg',
        ),
        throwsA(
          isA<ApiException>().having(
            (error) => error.fields['file'],
            'file errors',
            ['Format tidak didukung.'],
          ),
        ),
      );
    });

    test(
      'multipart upload preserves conflict fields and does not expire a session on 409',
      () async {
        final transport = RecordingTransport()
          ..responses.add(
            ApiResponse(
              409,
              jsonEncode({
                'error': {
                  'code': 'ATTACHMENT_CONFLICT',
                  'message': 'Bukti tidak dapat digunakan.',
                  'fields': {
                    'file': ['Coba unggah ulang.'],
                  },
                },
                'request_id': 'upload-conflict',
              }),
            ),
          );
        final store = _TokenStore()
          ..values['civora.teacher.token'] = 'teacher-session';
        final session = SessionController(role: 'teacher', tokenStore: store)
          ..bearerToken = 'teacher-session'
          ..state = SessionState.authenticated;
        final client = ApiClient(
          baseUrl: Uri.parse('http://localhost/api/v1'),
          transport: transport,
          sessionController: session,
        );
        await expectLater(
          client.uploadFile(
            '/teacher/attachments',
            bytes: Uint8List.fromList([1]),
            filename: 'proof.jpg',
            mimeType: 'image/jpeg',
          ),
          throwsA(
            isA<ApiException>()
                .having((error) => error.statusCode, 'statusCode', 409)
                .having((error) => error.fields['file'], 'file errors', [
                  'Coba unggah ulang.',
                ]),
          ),
        );
        expect(session.state, SessionState.authenticated);
      },
    );

    test('stale multipart 401 cannot end a newer session generation', () async {
      final response = Completer<ApiResponse>();
      final transport = _DelayedTransport(response);
      final session =
          SessionController(role: 'teacher', tokenStore: _TokenStore())
            ..bearerToken = 'old-session'
            ..state = SessionState.authenticated;
      final client = ApiClient(
        baseUrl: Uri.parse('http://localhost/api/v1'),
        transport: transport,
        sessionController: session,
      );
      final upload = client.uploadFile(
        '/teacher/attachments',
        bytes: Uint8List.fromList([1]),
        filename: 'proof.jpg',
        mimeType: 'image/jpeg',
      );
      session.end();
      session.bearerToken = 'new-session';
      session.state = SessionState.authenticated;
      final request = await transport.requestSent.future;
      expect(request.headers['Authorization'], 'Bearer old-session');
      response.complete(
        ApiResponse(
          401,
          jsonEncode({
            'error': {'code': 'UNAUTHENTICATED', 'message': 'Sesi lama habis.'},
          }),
        ),
      );
      await expectLater(upload, throwsA(isA<ApiException>()));
      expect(session.bearerToken, 'new-session');
      expect(session.state, SessionState.authenticated);
    });
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
        'student': {
          'id': 'student-2',
          'nisn': '0002',
          'education_status': 'active',
        },
      });

      expect(raw.studentId, 'student-1');
      expect(raw.student, isNull);
      expect(raw.expiresAt.isUtc, isTrue);
      expect(enriched.student?.nisn, '0002');
    });

    test('preserves structured conflict details', () async {
      final transport = RecordingTransport()
        ..responses.add(
          ApiResponse(
            409,
            jsonEncode({
              'error': {
                'code': 'IDEMPOTENCY_CONFLICT',
                'message': 'Kunci sudah digunakan.',
                'fields': {
                  'key': ['Gunakan kunci baru.'],
                },
              },
              'request_id': 'request-1',
            }),
          ),
        );
      final client = ApiClient(
        baseUrl: Uri.parse('http://localhost/api/v1'),
        transport: transport,
      );

      await expectLater(
        client.post('/teacher/records', const {}),
        throwsA(
          isA<ApiException>()
              .having((e) => e.statusCode, 'statusCode', 409)
              .having((e) => e.code, 'code', 'IDEMPOTENCY_CONFLICT')
              .having((e) => e.message, 'message', 'Kunci sudah digunakan.')
              .having((e) => e.fields['key'], 'fields', ['Gunakan kunci baru.'])
              .having((e) => e.requestId, 'requestId', 'request-1'),
        ),
      );
    });

    test('login never sends a prior bearer token', () async {
      final transport = RecordingTransport()
        ..responses.add(
          ApiResponse(
            200,
            jsonEncode({
              'data': {'token': 'new'},
            }),
          ),
        );
      final session = SessionController(
        role: 'teacher',
        tokenStore: _TokenStore(),
      )..bearerToken = 'old-token';
      final client = ApiClient(
        baseUrl: Uri.parse('http://localhost/api/v1'),
        transport: transport,
        sessionController: session,
      );

      await client.post('/auth/student/login', const {
        'nisn': '001',
        'password': 'secret',
      });

      expect(
        transport.requests.single.headers,
        isNot(contains('Authorization')),
      );
      expect(session.bearerToken, 'old-token');
    });

    test('401 ends the current session', () async {
      final transport = RecordingTransport()
        ..responses.add(
          ApiResponse(
            401,
            jsonEncode({
              'error': {
                'code': 'INVALID_CREDENTIALS',
                'message': 'Sesi habis.',
              },
            }),
          ),
        );
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

    test('private attachment bytes use the current bearer session', () async {
      final transport = RecordingTransport()
        ..responses.add(
          ApiResponse(200, '', bytes: Uint8List.fromList([137, 80, 78, 71])),
        );
      final session = SessionController(
        role: 'student',
        tokenStore: _TokenStore(),
      )..bearerToken = 'student-session';
      final client = ApiClient(
        baseUrl: Uri.parse('http://localhost/api/v1'),
        transport: transport,
        sessionController: session,
      );

      final bytes = await client.getBytes('/attachments/private-id');

      expect(bytes, [137, 80, 78, 71]);
      expect(
        transport.requests.single.headers['Authorization'],
        'Bearer student-session',
      );
      expect(
        transport.requests.single.uri.path,
        '/api/v1/attachments/private-id',
      );
    });

    test('formats UTC instants in the saved IANA school timezone', () {
      expect(
        formatSchoolDate(
          DateTime.parse('2026-01-01T00:00:00Z'),
          'Asia/Makassar',
        ),
        '01 Jan 2026, 08:00',
      );
      expect(formatSchoolDate(DateTime.utc(2026), null), isNull);
      expect(formatSchoolDate(DateTime.utc(2026), 'Unknown/Zone'), isNull);
    });
    test(
      'keeps pending profile minimal and parses nullable teacher identity',
      () {
        final pending = MeDto.fromJson({
          'id': 'user-1',
          'name': 'Siswa',
          'role': 'student',
          'account_status': 'pending_activation',
          'must_change_password': true,
        });
        final teacher = MeDto.fromJson({
          'id': 'user-2',
          'name': 'Guru',
          'role': 'teacher',
          'account_status': 'active',
          'must_change_password': false,
          'teacher': {'id': 'teacher-2', 'nip': null, 'teacher_code': 'G-002'},
          'current_semester': {'id': 'semester-1', 'name': 'Ganjil'},
        });
        expect(pending.student, isNull);
        expect(pending.currentSemester, isNull);
        expect(pending.account.mustChangePassword, isTrue);
        expect(teacher.teacher?.nip, isNull);
        expect(teacher.currentSemester?.id, 'semester-1');
        expect(teacher.schoolTimezone, isNull);

        final activeStudent = MeDto.fromJson({
          'id': 'user-3',
          'name': 'Siswa',
          'role': 'student',
          'account_status': 'active',
          'must_change_password': false,
          'school_timezone': 'Asia/Makassar',
        });
        expect(activeStudent.schoolTimezone, 'Asia/Makassar');
      },
    );
  });
}
