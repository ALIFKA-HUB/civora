<?php

namespace App\Actions\Points;

use App\Models\QrRedemption;
use App\Models\QrToken;
use App\Models\Student;
use App\Models\StudentSelection;
use App\Models\Teacher;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RedeemStudentQr
{
    public function execute(User $teacherUser, string $token, string $requestKey): StudentSelection
    {
        if (! preg_match('/^civora1_[A-Za-z0-9_-]{43}$/', $token)) {
            throw ValidationException::withMessages(['token' => 'QR tidak valid atau sudah kedaluwarsa.']);
        }
        if ($requestKey === '' || mb_strlen($requestKey) > 100) {
            throw ValidationException::withMessages(['request_key' => 'Kunci permintaan tidak valid.']);
        }

        $hash = hash('sha256', $token);
        $tokenId = QrToken::where('token_hash', $hash)->value('id');
        if (! $tokenId) {
            throw ValidationException::withMessages(['token' => 'QR tidak valid atau sudah kedaluwarsa.']);
        }

        return DB::transaction(function () use ($teacherUser, $requestKey, $hash, $tokenId): StudentSelection {
            // The global order is semester, student, then QR and selection resources.
            $semester = StudentSelectionRules::activeSemester();
            $studentId = QrToken::whereKey($tokenId)->value('student_id');
            if (! $studentId) {
                throw ValidationException::withMessages(['token' => 'QR tidak valid atau sudah kedaluwarsa.']);
            }
            $student = Student::whereKey($studentId)->lockForUpdate()->first();
            $teacherUser = StudentSelectionRules::teacher($teacherUser, true);
            if (! $student) {
                throw ValidationException::withMessages(['token' => 'QR tidak valid atau sudah kedaluwarsa.']);
            }
            $student = StudentSelectionRules::student($student, false, true);
            $enrollment = StudentSelectionRules::enrollment($student, $semester);
            $qr = QrToken::whereKey($tokenId)->lockForUpdate()->first();
            if (! $qr || $qr->student_id !== $student->id || ! hash_equals($qr->token_hash, $hash)) {
                throw ValidationException::withMessages(['token' => 'QR tidak valid atau sudah kedaluwarsa.']);
            }
            $redemption = QrRedemption::where('qr_token_id', $qr->id)->lockForUpdate()->first();
            if ($redemption) {
                $selection = StudentSelection::whereKey($redemption->selection_id)->lockForUpdate()->first();
                if ($redemption->teacher_id !== Teacher::where('user_id', $teacherUser->id)->value('id')
                    || $redemption->request_key !== $requestKey || ! $selection
                    || $selection->expires_at->lessThanOrEqualTo(CarbonImmutable::now())
                    || $selection->enrollment_id !== $enrollment->id || $selection->semester_id !== $semester->id) {
                    throw ValidationException::withMessages(['token' => 'QR sudah digunakan atau pemilihan siswa telah berubah.']);
                }
                if (! StudentSelectionRules::issuingSessionIsValid($qr->session_id, $student->user_id)) {
                    throw ValidationException::withMessages(['token' => 'QR dari sesi siswa yang sudah dicabut tidak dapat digunakan.']);
                }

                return $selection;
            }
            if ($qr->revoked_at || $qr->expires_at->lessThanOrEqualTo(CarbonImmutable::now()) || $qr->consumed_at) {
                throw ValidationException::withMessages(['token' => 'QR tidak valid atau sudah kedaluwarsa.']);
            }
            if (! StudentSelectionRules::issuingSessionIsValid($qr->session_id, $student->user_id)) {
                throw ValidationException::withMessages(['token' => 'QR dari sesi siswa yang sudah dicabut tidak dapat digunakan.']);
            }
            $teacher = Teacher::where('user_id', $teacherUser->id)->firstOrFail();
            if (QrRedemption::where('teacher_id', $teacher->id)->where('request_key', $requestKey)->exists()) {
                throw ValidationException::withMessages(['request_key' => 'Kunci permintaan sudah digunakan untuk QR lain.']);
            }

            $now = CarbonImmutable::now();
            $selection = StudentSelection::create([
                'teacher_id' => $teacher->id,
                'student_id' => $student->id,
                'enrollment_id' => $enrollment->id,
                'semester_id' => $semester->id,
                'source' => 'qr',
                'expires_at' => $now->addMinutes(5),
            ]);
            $qr->update(['consumed_at' => $now]);
            QrRedemption::create(['qr_token_id' => $qr->id, 'teacher_id' => $teacher->id, 'request_key' => $requestKey, 'selection_id' => $selection->id]);

            return $selection;
        }, 3);
    }
}
