<?php

namespace App\Actions\Points;

use App\Actions\Accounts\AccountSecurity;
use App\Models\QrToken;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

final class IssueStudentQr
{
    /** @return array{token:string,expires_at:string,server_time:string} */
    public function execute(User $studentUser): array
    {
        $currentToken = $studentUser->currentAccessToken();
        if (! $currentToken instanceof PersonalAccessToken || ! $currentToken->exists
            || $currentToken->tokenable_id !== $studentUser->id || $currentToken->tokenable_type !== $studentUser::class) {
            throw new AuthorizationException('Sesi siswa tidak sah.');
        }

        return DB::transaction(function () use ($studentUser, $currentToken): array {
            $semester = StudentSelectionRules::activeSemester();
            $student = Student::where('user_id', $studentUser->id)->lockForUpdate()->firstOrFail();
            $student = StudentSelectionRules::student($student, true, true);
            $user = $student->user;
            if (! $user || $user->role !== 'student' || ! AccountSecurity::active($user)) {
                throw new AuthorizationException('Akun siswa belum aktif.');
            }
            StudentSelectionRules::enrollment($student, $semester);
            $issuingToken = PersonalAccessToken::whereKey($currentToken->getKey())->first();
            if (! $issuingToken || ! StudentSelectionRules::issuingSessionIsValid($issuingToken->getKey(), $user->id)) {
                throw new AuthorizationException('Sesi siswa tidak sah.');
            }

            $now = CarbonImmutable::now();
            QrToken::where('student_id', $student->id)->whereNull('revoked_at')->update(['revoked_at' => $now]);
            $raw = 'civora1_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $qr = QrToken::create([
                'student_id' => $student->id,
                'session_id' => $issuingToken->getKey(),
                'token_hash' => hash('sha256', $raw),
                'issued_at' => $now,
                'expires_at' => $now->addSeconds(20),
            ]);

            return ['token' => $raw, 'expires_at' => $qr->expires_at->utc()->toISOString(), 'server_time' => $now->utc()->toISOString()];
        }, 3);
    }
}
