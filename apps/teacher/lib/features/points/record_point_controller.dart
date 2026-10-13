import 'dart:math';
import 'dart:typed_data';

import 'package:civora_core/civora_core.dart';

class TeacherStudent {
  const TeacherStudent({
    required this.id,
    required this.name,
    required this.nisn,
    this.classroom = '',
    this.major = '',
  });
  final String id;
  final String name;
  final String nisn;
  final String classroom;
  final String major;

  factory TeacherStudent.fromJson(Map<String, dynamic> json) => TeacherStudent(
    id: json['id'] as String,
    name: json['name'] as String,
    nisn: json['nisn'] as String,
    classroom:
        (json['classroom'] as Map<String, dynamic>?)?['name'] as String? ?? '',
    major: (json['major'] as Map<String, dynamic>?)?['name'] as String? ?? '',
  );

  String get accessibleLabel =>
      '$name, NISN $nisn${classroom.isEmpty ? '' : ', kelas $classroom'}';
}

class StudentProfileLoadException implements Exception {
  const StudentProfileLoadException(this.cause);
  final Object cause;
}

class RecordPointController {
  RecordPointController(this.api, {DateTime Function()? clock})
    : _clock = clock ?? DateTime.now;

  final ApiClient api;
  final DateTime Function() _clock;
  final Map<String, Future<SelectionDto>> _qrRequests = {};
  SelectionDto? _selection;
  SelectionDto? get selection => _selection;
  Map<String, dynamic>? _studentProfile;
  Map<String, dynamic>? get studentProfile => _studentProfile;
  List<PointTypeDto> _pointTypes = const [];
  List<PointTypeDto> get pointTypes => List.unmodifiable(_pointTypes);
  PointTypeDto? _selectedPointType;
  PointTypeDto? get selectedPointType => _selectedPointType;
  String _description = '';
  String get description => _description;
  set description(String value) {
    _ensureDraftMutable();
    _description = value;
  }

  String? _photoId;
  String? get photoId => _photoId;
  String? _photoName;
  String? get photoName => _photoName;
  int? _currentTotalAchievement;
  int? get currentTotalAchievement => _currentTotalAchievement;
  int? _currentTotalViolation;
  int? get currentTotalViolation => _currentTotalViolation;
  String? _currentSemesterName;
  String? get currentSemesterName => _currentSemesterName;
  Map<String, dynamic>? _submittedPayload;
  String? _idempotencyKey;
  PointRecordDto? _savedRecord;
  PointRecordDto? get savedRecord => _savedRecord;
  bool _hasUnknownAttempt = false;
  bool get hasUnknownAttempt => _hasUnknownAttempt;
  bool _requiresReconfirmation = false;
  bool get requiresReconfirmation => _requiresReconfirmation;
  bool _selectionInvalidated = false;
  bool _retryingUnknown = false;
  bool _resolvingUnknown = false;
  bool _submitting = false;
  bool get isAttemptLocked =>
      _hasUnknownAttempt ||
      _retryingUnknown ||
      _resolvingUnknown ||
      _submitting;

  bool get selectionExpired =>
      _selection != null && !_clock().toUtc().isBefore(_selection!.expiresAt);
  bool get requiresStudentReselection =>
      selectionExpired || _selectionInvalidated;
  bool get canSubmit =>
      _selection != null &&
      !selectionExpired &&
      selectedPointType != null &&
      description.trim().runes.length >= 5 &&
      description.trim().runes.length <= 1000 &&
      (!_hasUnknownAttempt || _retryingUnknown) &&
      !_submitting;

  Future<SelectionDto> selectQr(String token, String requestKey) {
    _ensureNoUnknownAttempt();
    final existing = _qrRequests['$token:$requestKey'];
    if (existing != null) return existing;
    final future = _selectQr(token, requestKey);
    _qrRequests['$token:$requestKey'] = future;
    return future;
  }

  Future<SelectionDto> _selectQr(String token, String requestKey) async {
    final value = await api.post('/teacher/qr/redeem', {
      'token': token,
      'request_key': requestKey,
    });
    return _installSelection(SelectionDto.fromJson(value));
  }

  Future<SelectionDto> selectManual(String studentId) async {
    _ensureNoUnknownAttempt();
    final value = await api.post('/teacher/student-selections', {
      'student_id': studentId,
    });
    return _installSelection(SelectionDto.fromJson(value));
  }

  Future<SelectionDto> _installSelection(SelectionDto next) async {
    _selection = next;
    _studentProfile = null;
    _savedRecord = null;
    _submittedPayload = null;
    _idempotencyKey = null;
    _hasUnknownAttempt = false;
    _requiresReconfirmation = false;
    _selectionInvalidated = false;
    _photoId = null;
    _photoName = null;
    await retryStudentProfile();
    return next;
  }

  Future<Map<String, dynamic>> retryStudentProfile() async {
    final current = _selection;
    if (current == null) throw StateError('Belum ada pemilihan siswa.');
    try {
      // Keep the selection ID exactly as returned by scan/manual selection. The
      // existing authorized profile endpoint supplies the display identity.
      _studentProfile = await api.get('/teacher/students/${current.studentId}');
      _currentSemesterName =
          (_studentProfile?['semester'] as Map<String, dynamic>?)?['name']
              as String?;
      final totals = _studentProfile?['totals'] as Map<String, dynamic>?;
      _currentTotalAchievement = totals?['achievement'] as int?;
      _currentTotalViolation = totals?['violation'] as int?;
      return _studentProfile!;
    } catch (error) {
      throw StudentProfileLoadException(error);
    }
  }

  Future<void> loadPointTypes() async {
    final response = await api.get('/teacher/point-types');
    _pointTypes =
        (response['items'] as List<dynamic>? ??
                response['data'] as List<dynamic>? ??
                const [])
            .map((item) => PointTypeDto.fromJson(item as Map<String, dynamic>))
            .toList();
  }

  void selectPointType(String versionId) {
    _ensureDraftMutable();
    _selectedPointType = _pointTypes
        .where((type) => type.versionId == versionId)
        .firstOrNull;
    if (selectedPointType != null) _requiresReconfirmation = false;
    if (_submittedPayload != null) _resetAttempt();
  }

  static void validatePhoto({
    required List<int> bytes,
    required String filename,
    required String mimeType,
  }) {
    if (bytes.isEmpty || bytes.length > 5 * 1024 * 1024) {
      throw ArgumentError('Foto harus berukuran maksimal 5 MB.');
    }
    final isJpeg =
        mimeType == 'image/jpeg' &&
        (filename.toLowerCase().endsWith('.jpg') ||
            filename.toLowerCase().endsWith('.jpeg')) &&
        bytes.length >= 3 &&
        bytes[0] == 0xff &&
        bytes[1] == 0xd8 &&
        bytes[2] == 0xff;
    final isPng =
        mimeType == 'image/png' &&
        filename.toLowerCase().endsWith('.png') &&
        bytes.length >= 8 &&
        bytes.take(8).join(',') == '137,80,78,71,13,10,26,10';
    if (!isJpeg && !isPng) {
      throw ArgumentError('Foto harus berupa JPG atau PNG yang valid.');
    }
  }

  Future<String> uploadPhoto({
    required Uint8List bytes,
    required String filename,
    required String mimeType,
  }) async {
    _ensureDraftMutable();
    validatePhoto(bytes: bytes, filename: filename, mimeType: mimeType);
    final response = await api.uploadFile(
      '/teacher/attachments',
      bytes: bytes,
      filename: filename,
      mimeType: mimeType,
    );
    _ensureDraftMutable();
    _photoId = response['id'] as String;
    _photoName = filename;
    return _photoId!;
  }

  void removePhoto() {
    _ensureDraftMutable();
    _photoId = null;
    _photoName = null;
  }

  Future<PointRecordDto> submit() async {
    if ((_hasUnknownAttempt && !_retryingUnknown) || _submitting) {
      throw StateError('Periksa hasil upaya sebelumnya terlebih dahulu.');
    }
    if (!canSubmit) {
      if (selectionExpired) {
        throw StateError('Pemilihan siswa kedaluwarsa. Pilih siswa kembali.');
      }
      throw StateError('Lengkapi jenis poin dan keterangan sebelum menyimpan.');
    }
    if (_submittedPayload == null) {
      _idempotencyKey = _newRequestKey();
      _submittedPayload = <String, dynamic>{
        'selection_id': selection!.id,
        'point_type_version_id': selectedPointType!.versionId,
        'description': description.trim(),
        if (photoId != null) 'attachment_id': photoId,
      };
    }
    _submitting = true;
    try {
      final response = await api.post(
        '/teacher/records',
        _submittedPayload!,
        idempotencyKey: _idempotencyKey,
      );
      _savedRecord = PointRecordDto.fromJson(response);
      _hasUnknownAttempt = false;
      try {
        final refreshed = await api.get(
          '/teacher/students/${_selection!.studentId}',
        );
        final totals = refreshed['totals'] as Map<String, dynamic>?;
        _currentTotalAchievement = totals?['achievement'] as int?;
        _currentTotalViolation = totals?['violation'] as int?;
      } catch (_) {
        // The record response is authoritative for success; summary can reload later.
      }
      return _savedRecord!;
    } on ApiException catch (error) {
      if (error.statusCode == 409 ||
          error.statusCode == 410 ||
          error.statusCode == 422) {
        _requiresReconfirmation = true;
        _resetAttempt();
        if (error.code.contains('POINT_TYPE') ||
            error.code.contains('CATALOG')) {
          _selectedPointType = null;
          await loadPointTypes();
        }
        if (error.code.contains('SEMESTER') ||
            error.code.contains('PLACEMENT')) {
          _selectionInvalidated = true;
          try {
            final profile = await api.get('/me');
            _currentSemesterName =
                (profile['current_semester'] as Map<String, dynamic>?)?['name']
                    as String?;
          } on ApiException {
            // The expired selection remains blocked; the protected root handles session expiry.
          }
        }
      }
      rethrow;
    } catch (_) {
      _hasUnknownAttempt = true;
      rethrow;
    } finally {
      _submitting = false;
    }
  }

  Future<PointRecordDto?> resolveUnknownAttempt() async {
    if (!hasUnknownAttempt || _idempotencyKey == null) return _savedRecord;
    if (_resolvingUnknown) {
      throw StateError('Pemeriksaan upaya sebelumnya masih berlangsung.');
    }
    _resolvingUnknown = true;
    try {
      final response = await api.get(
        '/teacher/record-attempts/$_idempotencyKey',
      );
      _savedRecord = PointRecordDto.fromJson(response);
      _hasUnknownAttempt = false;
      return _savedRecord;
    } on ApiException catch (error) {
      if (error.statusCode == 404) return null;
      rethrow;
    } finally {
      _resolvingUnknown = false;
    }
  }

  Future<PointRecordDto> retryUnknownAttempt() {
    if (!hasUnknownAttempt || _submittedPayload == null) {
      throw StateError('Tidak ada upaya yang belum diketahui.');
    }
    if (_retryingUnknown) {
      throw StateError('Upaya sebelumnya masih dikirim.');
    }
    _retryingUnknown = true;
    return submit().whenComplete(() => _retryingUnknown = false);
  }

  void _ensureNoUnknownAttempt() => _ensureDraftMutable();

  void _ensureDraftMutable() {
    if (_hasUnknownAttempt ||
        _retryingUnknown ||
        _resolvingUnknown ||
        _submitting) {
      throw StateError(
        'Simpan atau selesaikan upaya sebelumnya terlebih dahulu.',
      );
    }
  }

  void _resetAttempt() {
    _submittedPayload = null;
    _idempotencyKey = null;
    _hasUnknownAttempt = false;
  }

  String _newRequestKey() {
    final random = Random.secure();
    final bytes = List<int>.generate(16, (_) => random.nextInt(256));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    final hex = bytes
        .map((byte) => byte.toRadixString(16).padLeft(2, '0'))
        .join();
    return '${hex.substring(0, 8)}-${hex.substring(8, 12)}-${hex.substring(12, 16)}-${hex.substring(16, 20)}-${hex.substring(20)}';
  }
}
