
DateTime _utc(Object? value) => DateTime.parse(value! as String).toUtc();
Map<String, dynamic> _map(Object? value) => value is Map<String, dynamic> ? value : const {};

class AccountDto {
  const AccountDto({required this.id, required this.name, required this.role, required this.accountStatus, required this.mustChangePassword});
  final String id;
  final String name;
  final String role;
  final String accountStatus;
  final bool mustChangePassword;

  factory AccountDto.fromJson(Map<String, dynamic> json) => AccountDto(
    id: json['id'] as String,
    name: json['name'] as String,
    role: json['role'] as String,
    accountStatus: json['account_status'] as String,
    mustChangePassword: json['must_change_password'] as bool,
  );
}

class SemesterDto {
  const SemesterDto({required this.id, required this.name});
  final String id;
  final String name;
  factory SemesterDto.fromJson(Map<String, dynamic> json) => SemesterDto(id: json['id'] as String, name: json['name'] as String);
}

class StudentDto {
  const StudentDto({required this.id, required this.nisn, required this.educationStatus});
  final String id;
  final String nisn;
  final String educationStatus;
  factory StudentDto.fromJson(Map<String, dynamic> json) => StudentDto(id: json['id'] as String, nisn: json['nisn'] as String, educationStatus: json['education_status'] as String);
}

class TeacherDto {
  const TeacherDto({required this.id, this.nip, required this.teacherCode});
  final String id;
  final String? nip;
  final String? teacherCode;
  factory TeacherDto.fromJson(Map<String, dynamic> json) => TeacherDto(id: json['id'] as String, nip: json['nip'] as String?, teacherCode: json['teacher_code'] as String?);
}

class SchoolGroupDto {
  const SchoolGroupDto({required this.id, required this.name, this.code});
  final String id;
  final String name;
  final String? code;
  factory SchoolGroupDto.fromJson(Map<String, dynamic> json) => SchoolGroupDto(id: json['id'] as String, name: json['name'] as String, code: json['code'] as String?);
}

class EnrollmentDto {
  const EnrollmentDto({required this.id, required this.semesterId, required this.classroom, required this.major, this.validFrom, this.validUntil});
  final String id;
  final String semesterId;
  final SchoolGroupDto classroom;
  final SchoolGroupDto major;
  final DateTime? validFrom;
  final DateTime? validUntil;
  factory EnrollmentDto.fromJson(Map<String, dynamic> json) {
    final classroomJson = _map(json['classroom']);
    final majorJson = _map(json['major'] ?? classroomJson['major']);
    return EnrollmentDto(
      id: json['id'] as String,
      semesterId: json['semester_id'] as String? ?? '',
      classroom: SchoolGroupDto.fromJson(classroomJson),
      major: SchoolGroupDto.fromJson(majorJson),
      validFrom: json['valid_from'] == null ? null : _utc(json['valid_from']),
      validUntil: json['valid_until'] == null ? null : _utc(json['valid_until']),
    );
  }
}
class MeDto {
  const MeDto({required this.account, this.student, this.teacher, this.enrollments = const [], this.currentSemester, this.ownEnrollment});
  final AccountDto account;
  final StudentDto? student;
  final TeacherDto? teacher;
  final List<EnrollmentDto> enrollments;
  final SemesterDto? currentSemester;
  final EnrollmentDto? ownEnrollment;

  factory MeDto.fromJson(Map<String, dynamic> json) => MeDto(
    account: AccountDto.fromJson(json),
    student: json['student'] == null ? null : StudentDto.fromJson(_map(json['student'])),
    teacher: json['teacher'] == null ? null : TeacherDto.fromJson(_map(json['teacher'])),
    enrollments: (json['enrollments'] as List<dynamic>? ?? const []).map((item) => EnrollmentDto.fromJson(_map(item))).toList(),
    currentSemester: json['current_semester'] == null ? null : SemesterDto.fromJson(_map(json['current_semester'])),
    ownEnrollment: json['own_enrollment'] == null ? null : _ownEnrollment(_map(json['own_enrollment'])),
  );

  static EnrollmentDto _ownEnrollment(Map<String, dynamic> json) => EnrollmentDto(
    id: json['id'] as String,
    semesterId: '',
    classroom: SchoolGroupDto.fromJson(_map(json['classroom'])),
    major: SchoolGroupDto.fromJson(_map(json['major'])),
  );
}

class AccountLoginDto {
  const AccountLoginDto({required this.token, required this.tokenType, required this.expiresAt, required this.account});
  final String token;
  final String tokenType;
  final DateTime expiresAt;
  final AccountDto account;
  factory AccountLoginDto.fromJson(Map<String, dynamic> json) => AccountLoginDto(
    token: json['token'] as String,
    tokenType: json['token_type'] as String,
    expiresAt: _utc(json['expires_at']),
    account: AccountDto.fromJson(json),
  );
}

class PointTypeDto {
  const PointTypeDto({required this.versionId, required this.name, required this.category, required this.points});
  final String versionId;
  final String name;
  final String category;
  final int points;
  factory PointTypeDto.fromJson(Map<String, dynamic> json) => PointTypeDto(versionId: json['version_id'] as String, name: json['name'] as String, category: json['category'] as String, points: json['points'] as int);
}

class SelectionDto {
  const SelectionDto({required this.id, required this.expiresAt, required this.studentId, this.student});
  final String id;
  final DateTime expiresAt;
  final String studentId;
  final StudentDto? student;
  factory SelectionDto.fromJson(Map<String, dynamic> json) => SelectionDto(
    id: json['id'] as String,
    expiresAt: _utc(json['expires_at']),
    studentId: json['student_id'] as String,
    student: json['student'] == null ? null : StudentDto.fromJson(_map(json['student'])),
  );
}
class QrDto {
  const QrDto({required this.token, required this.expiresAt, required this.serverTime});
  final String token;
  final DateTime expiresAt;
  final DateTime serverTime;
  factory QrDto.fromJson(Map<String, dynamic> json) => QrDto(token: json['token'] as String, expiresAt: _utc(json['expires_at']), serverTime: _utc(json['server_time']));
}

class SummaryDto {
  const SummaryDto({this.semester, this.achievement, this.violation, this.latestThresholdStatus});
  final SemesterDto? semester;
  final int? achievement;
  final int? violation;
  final Map<String, dynamic>? latestThresholdStatus;

  factory SummaryDto.fromJson(Map<String, dynamic> json) {
    final totals = json['totals'] == null ? null : _map(json['totals']);
    final threshold = json['latest_threshold_status'];
    return SummaryDto(
      semester: json['semester'] == null ? null : SemesterDto.fromJson(_map(json['semester'])),
      achievement: totals?['achievement'] as int?,
      violation: totals?['violation'] as int?,
      latestThresholdStatus: threshold == null ? null : _map(threshold),
    );
  }
}

class PointRecordDto {
  const PointRecordDto({required this.id, required this.source, required this.occurredAt, required this.student, required this.teacher, required this.semester, this.latestRevision, this.reviewStatus});
  final String id;
  final String source;
  final DateTime occurredAt;
  final Map<String, dynamic> student;
  final Map<String, dynamic> teacher;
  final SemesterDto semester;
  final Map<String, dynamic>? latestRevision;
  final String? reviewStatus;
  factory PointRecordDto.fromJson(Map<String, dynamic> json) => PointRecordDto(
    id: json['id'] as String,
    source: json['source'] as String,
    occurredAt: _utc(json['occurred_at']),
    student: _map(json['student']),
    teacher: _map(json['teacher']),
    semester: SemesterDto.fromJson(_map(json['semester'])),
    latestRevision: json['latest_revision'] == null ? null : _map(json['latest_revision']),
    reviewStatus: json['review_status'] as String?,
  );
}

class ReviewDto {
  const ReviewDto({required this.id, required this.recordId, required this.kind, required this.reason, required this.status, required this.createdAt, this.proposedChange, this.reviewedRevision});
  final String id;
  final String recordId;
  final String kind;
  final String reason;
  final String status;
  final DateTime createdAt;
  final Map<String, dynamic>? proposedChange;
  final int? reviewedRevision;
  factory ReviewDto.fromJson(Map<String, dynamic> json) => ReviewDto(
    id: json['id'] as String,
    recordId: json['record_id'] as String,
    kind: json['kind'] as String,
    reason: json['reason'] as String,
    status: json['status'] as String,
    createdAt: _utc(json['created_at']),
    proposedChange: json['proposed_change'] == null ? null : _map(json['proposed_change']),
    reviewedRevision: json['reviewed_revision'] as int?,
  );
}

class NotificationDto {
  const NotificationDto({required this.id, required this.type, required this.message, required this.createdAt, this.readAt, this.resourceId});
  final String id;
  final String type;
  final String message;
  final DateTime createdAt;
  final DateTime? readAt;
  final String? resourceId;
  factory NotificationDto.fromJson(Map<String, dynamic> json) => NotificationDto(
    id: json['id'] as String,
    type: json['type'] as String,
    message: json['message'] as String,
    createdAt: _utc(json['created_at']),
    readAt: json['read_at'] == null ? null : _utc(json['read_at']),
    resourceId: json['resource_id'] as String?,
  );
}
