import 'dart:convert';
import 'dart:io';

import 'package:civora_core/civora_core.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test(
    'ApiClient completes mobile workflows against the local Laravel API',
    () async {
      final root = Directory.current.parent.parent.path;
      final studentConfig = await _readConfig(
        '$root/docs/qa/mobile.student.json',
      );
      final teacherConfig = await _readConfig(
        '$root/docs/qa/mobile.teacher.json',
      );
      final baseUrl = _loopbackUrl(
        studentConfig['CIVORA_API_BASE_URL'] as String,
      );
      final student = _client(baseUrl, 'student');
      final teacher = _client(baseUrl, 'teacher');
      try {
        await _login(
          student,
          studentConfig['CIVORA_QA_STUDENT_NISN'] as String,
          studentConfig['CIVORA_QA_STUDENT_PASSWORD'] as String,
          'student',
        );
        _step('student login and /me');

        final studentRecords = await student.api.get('/student/records');
        final recordName =
            studentConfig['CIVORA_QA_STUDENT_RECORD_NAME'] as String;
        final seedRecord = _items(studentRecords, 'data')
            .cast<Map<String, dynamic>>()
            .firstWhere(
              (row) => row['latest_revision']?['name'] == recordName,
              orElse: () =>
                  throw StateError('Synthetic objection record was not found.'),
            );
        final objection = await student.api.post(
          '/student/records/${seedRecord['id']}/objections',
          {'reason': 'Mohon periksa kembali catatan uji ini.'},
        );
        if (objection['status'] != 'pending') {
          throw StateError('Student objection did not remain pending.');
        }
        _step('student login, history, and objection');

        await _login(
          teacher,
          teacherConfig['CIVORA_QA_TEACHER_IDENTIFIER'] as String,
          teacherConfig['CIVORA_QA_TEACHER_PASSWORD'] as String,
          'teacher',
        );
        final qr = await student.api.post('/student/qr', const {});
        final rawQr = qr['token'];
        if (rawQr is! String || rawQr.isEmpty) {
          throw StateError('Student QR endpoint returned no token.');
        }
        final studentNisn = teacherConfig['CIVORA_QA_STUDENT_NISN'] as String;
        final studentName = teacherConfig['CIVORA_QA_STUDENT_NAME'] as String;
        final search = await teacher.api.get(
          '/teacher/students',
          query: {'q': studentNisn, 'per_page': 10},
        );
        final selectedStudent = _items(search, 'data')
            .cast<Map<String, dynamic>>()
            .firstWhere(
              (row) => row['nisn'] == studentNisn && row['name'] == studentName,
              orElse: () => throw StateError(
                'Synthetic student was not found for the teacher.',
              ),
            );

        final qrSelection = await teacher.api.post('/teacher/qr/redeem', {
          'token': rawQr,
          'request_key': _requestKey(),
        });
        final types = await teacher.api.get('/teacher/point-types');
        final pointTypes = _items(types, 'data').cast<Map<String, dynamic>>();
        if (pointTypes.isEmpty) {
          throw StateError('No active synthetic point type was returned.');
        }
        final typeId = pointTypes.first['version_id'] as String;
        await _saveRecord(
          teacher.api,
          qrSelection,
          typeId,
          'M06 API smoke QR selection.',
        );

        final manualSelection = await teacher.api.post(
          '/teacher/student-selections',
          {'student_id': selectedStudent['id']},
        );
        final record = await _saveRecord(
          teacher.api,
          manualSelection,
          typeId,
          'M06 API smoke manual selection.',
        );
        final correction = await teacher.api.post(
          '/teacher/records/${record['id']}/correction-requests',
          {
            'reason': 'Mohon koreksi catatan API uji.',
            'proposed_change': {
              'operation': 'revise',
              'description': 'Keterangan hasil verifikasi API M06.',
            },
          },
        );
        if (correction['status'] != 'pending') {
          throw StateError(
            'Teacher correction request did not remain pending.',
          );
        }
        _step(
          'teacher QR redeem, manual selection, save, and correction request',
        );
      } on ApiException catch (error) {
        fail('API smoke failed: HTTP ${error.statusCode} ${error.code}.');
      } catch (error) {
        fail('API smoke failed: ${error.runtimeType}.');
      } finally {
        await _logout(student);
        await _logout(teacher);
        student.api.close();
        teacher.api.close();
      }
    },
  );
}

class _Session {
  _Session(this.api, this.controller);

  final ApiClient api;
  final SessionController controller;
}

class _MemoryTokenStore implements SessionTokenStore {
  final Map<String, String> _tokens = {};

  @override
  Future<String?> read(String key) async => _tokens[key];

  @override
  Future<void> write(String key, String token) async => _tokens[key] = token;

  @override
  Future<void> delete(String key) async => _tokens.remove(key);
}

_Session _client(Uri baseUrl, String role) {
  final controller = SessionController(
    role: role,
    tokenStore: _MemoryTokenStore(),
  );
  final api = ApiClient(baseUrl: baseUrl, sessionController: controller);
  controller.bindApi(api);
  return _Session(api, controller);
}

Future<void> _login(
  _Session session,
  String identifier,
  String password,
  String role,
) async {
  await session.controller.restore();
  await session.controller.login(identifier, password, role: role);
  if (session.controller.profile?.account.role != role) {
    throw StateError('Login returned an unexpected role.');
  }
}

Future<Map<String, dynamic>> _saveRecord(
  ApiClient api,
  Map<String, dynamic> selection,
  String pointTypeVersionId,
  String description,
) => api.post('/teacher/records', {
  'selection_id': selection['id'],
  'point_type_version_id': pointTypeVersionId,
  'description': description,
}, idempotencyKey: _requestKey());

Future<void> _logout(_Session session) async {
  try {
    if (session.controller.bearerToken != null) {
      await session.controller.logout();
    }
  } catch (_) {
    // Keep cleanup errors from masking the API workflow result.
  }
}

Future<Map<String, dynamic>> _readConfig(String path) async =>
    (jsonDecode(await File(path).readAsString()) as Map<String, dynamic>);

Uri _loopbackUrl(String raw) {
  final uri = Uri.parse(raw);
  return uri.host == '10.0.2.2' ? uri.replace(host: '127.0.0.1') : uri;
}

List<dynamic> _items(Map<String, dynamic> response, String key) {
  final items = response[key];
  if (items is! List<dynamic>) {
    throw StateError('Unexpected API response shape.');
  }
  return items;
}

String _requestKey() =>
    '${DateTime.now().microsecondsSinceEpoch}-${_randomSuffix()}';

String _randomSuffix() =>
    DateTime.now().millisecondsSinceEpoch.toRadixString(36);

void _step(String description) => stdout.writeln('PASS: $description');
