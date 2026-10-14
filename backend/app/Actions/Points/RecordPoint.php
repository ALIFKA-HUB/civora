<?php

namespace App\Actions\Points;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\PointRecord;
use App\Models\PointType;
use App\Models\PointTypeVersion;
use App\Models\RecordRevision;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudentSelection;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Academic\CurrentEnrollment;
use App\Services\Academic\SchoolCalendar;
use App\Services\Points\ThresholdEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RecordPoint
{
    public function execute(User $teacherUser, array $input, string $idempotencyKey): PointRecord
    {
        $teacherUser = User::whereKey($teacherUser->id)->firstOrFail();
        StudentSelectionRules::teacher($teacherUser);
        $teacherId = Teacher::where('user_id', $teacherUser->id)->value('id');
        abort_unless($teacherId, 403, 'Akses ditolak.');
        $data = $this->validate($input);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 100) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'Kunci upaya pencatatan wajib diisi.']);
        }
        $description = trim($data['description']);
        if (mb_strlen($description) < 5 || mb_strlen($description) > 1000) {
            throw ValidationException::withMessages(['description' => 'Keterangan harus berisi 5–1.000 karakter.']);
        }
        $canonical = ['selection_id' => $data['selection_id'], 'point_type_version_id' => $data['point_type_version_id'], 'description' => $description, 'attachment_id' => $data['attachment_id'] ?? null];
        $hash = hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $existing = PointRecord::with(['latestRevision', 'student.user', 'teacher.user', 'semester'])
            ->where('teacher_id', $teacherId)->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $this->retry($existing, $hash);
        }

        $identity = StudentSelection::whereKey($data['selection_id'])->first();
        if (! $identity) {
            throw ValidationException::withMessages(['selection_id' => 'Sesi pemilihan siswa tidak tersedia.']);
        }
        $versionIdentity = PointTypeVersion::whereKey($data['point_type_version_id'])->first();
        if (! $versionIdentity) {
            throw ValidationException::withMessages(['point_type_version_id' => 'Versi jenis poin tidak tersedia.']);
        }

        return DB::transaction(function () use ($teacherUser, $teacherId, $data, $canonical, $hash, $idempotencyKey, $identity, $versionIdentity, $description): PointRecord {
            $semester = Semester::whereKey($identity->semester_id)->lockForUpdate()->first();
            $student = Student::whereKey($identity->student_id)->lockForUpdate()->first();
            $teacherUser = User::whereKey($teacherUser->id)->lockForUpdate()->first();
            if (! $semester || ! $student || ! $teacherUser) {
                throw ValidationException::withMessages(['selection_id' => 'Sesi pemilihan siswa tidak tersedia.']);
            }
            StudentSelectionRules::teacher($teacherUser);
            $student = StudentSelectionRules::student($student, true, true);
            $existing = PointRecord::with(['latestRevision', 'student.user', 'teacher.user', 'semester'])
                ->where('teacher_id', $teacherId)->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                return $this->retry($existing, $hash);
            }
            if ($semester->status !== 'active' || ! (new SchoolCalendar)->contains($semester, CarbonImmutable::now())) {
                throw ValidationException::withMessages(['semester' => 'Semester tidak lagi aktif untuk pencatatan.']);
            }
            $selection = StudentSelection::whereKey($identity->id)->lockForUpdate()->firstOrFail();
            if ($selection->teacher_id !== $teacherId || $selection->student_id !== $student->id || $selection->semester_id !== $semester->id
                || $selection->consumed_at || $selection->expires_at->lessThanOrEqualTo(CarbonImmutable::now())) {
                throw ValidationException::withMessages(['selection_id' => 'Sesi pemilihan siswa sudah digunakan atau kedaluwarsa.']);
            }

            $type = PointType::whereKey($versionIdentity->point_type_id)->lockForUpdate()->first();
            $version = PointTypeVersion::whereKey($data['point_type_version_id'])->first();
            $latestId = PointTypeVersion::where('point_type_id', $versionIdentity->point_type_id)->orderByDesc('version')->value('id');
            if (! $type || ! $type->active || ! $version || $latestId !== $version->id || $version->point_type_id !== $type->id) {
                $this->conflict('POINT_TYPE_CHANGED', 'Jenis poin berubah. Muat ulang daftar poin.');
            }

            $enrollment = (new CurrentEnrollment)->forStudent($student, $semester, CarbonImmutable::now()->toIso8601String());
            if (! $enrollment || $enrollment->id !== $selection->enrollment_id || $enrollment->id !== $identity->enrollment_id) {
                $this->conflict('PLACEMENT_CHANGED', 'Penempatan siswa berubah. Pilih siswa kembali.');
            }
            $enrollment = Enrollment::whereKey($enrollment->id)->lockForUpdate()->first();
            $classroom = $enrollment?->classroom;
            $major = $classroom?->major;
            if (! $enrollment || ! $classroom || ! $classroom->active || ! $major || ! $major->active || $classroom->academic_year_id !== $semester->academic_year_id) {
                $this->conflict('PLACEMENT_CHANGED', 'Penempatan siswa berubah. Pilih siswa kembali.');
            }

            $attachment = null;
            if (! empty($canonical['attachment_id'])) {
                $attachment = Attachment::whereKey($canonical['attachment_id'])->lockForUpdate()->first();
                if (! $attachment || $attachment->owner_user_id !== $teacherUser->id || $attachment->recordRevisions()->exists()) {
                    throw ValidationException::withMessages(['attachment_id' => 'Bukti tidak tersedia untuk digunakan.']);
                }
            }

            $teacher = Teacher::whereKey($teacherId)->firstOrFail();
            $record = PointRecord::create([
                'student_id' => $student->id,
                'teacher_id' => $teacher->id,
                'enrollment_id' => $enrollment->id,
                'semester_id' => $semester->id,
                'selection_id' => $selection->id,
                'source' => $selection->source,
                'occurred_at' => CarbonImmutable::now(),
                'class_snapshot' => $classroom->name,
                'major_snapshot' => $major->name,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $hash,
            ]);
            RecordRevision::create([
                'point_record_id' => $record->id,
                'revision_number' => 1,
                'point_type_version_id' => $version->id,
                'points_snapshot' => $version->points,
                'name_snapshot' => $version->name,
                'category_snapshot' => $type->category,
                'description' => $description,
                'attachment_id' => $attachment?->id,
                'status' => 'active',
                'changed_by' => $teacherUser->id,
                'reason' => 'Catatan awal dari guru.',
            ]);
            $selection->update(['consumed_at' => CarbonImmutable::now()]);
            app(ThresholdEvaluator::class)->evaluate($student, $semester);
            AuditLog::create([
                'actor_user_id' => $teacherUser->id,
                'action' => 'point_record_created',
                'resource_type' => 'point_record',
                'resource_id' => $record->id,
                'safe_after' => ['student_id' => $student->id, 'semester_id' => $semester->id, 'source' => $selection->source],
                'request_id' => request()->attributes->get('request_id') ?? (string) Str::uuid(),
            ]);
            DB::table('outbox_events')->insert([
                'id' => (string) Str::uuid(),
                'event_key' => 'point-record-created:'.$record->id,
                'type' => 'point_record_created',
                'resource_id' => $record->id,
                'payload' => json_encode(['student_id' => $student->id, 'teacher_id' => $teacher->id], JSON_THROW_ON_ERROR),
                'created_at' => CarbonImmutable::now(),
            ]);

            return $record->load(['latestRevision', 'student.user', 'teacher.user', 'semester']);
        }, 3);
    }

    private function validate(array $input): array
    {
        $allowed = ['selection_id', 'point_type_version_id', 'description', 'attachment_id'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw ValidationException::withMessages(['body' => 'Field pencatatan tidak diizinkan.']);
        }

        return Validator::make($input, [
            'selection_id' => ['required', 'uuid'],
            'point_type_version_id' => ['required', 'uuid'],
            'description' => ['required', 'string'],
            'attachment_id' => ['nullable', 'uuid'],
        ])->validate();
    }

    private function retry(PointRecord $record, string $hash): PointRecord
    {
        if (! hash_equals($record->request_hash, $hash)) {
            $this->conflict('IDEMPOTENCY_CONFLICT', 'Kunci pencatatan sudah digunakan dengan isi berbeda.');
        }

        return $record;
    }

    private function conflict(string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(['error' => ['code' => $code, 'message' => $message], 'request_id' => request()->attributes->get('request_id') ?? (string) Str::uuid()], 409));
    }
}
