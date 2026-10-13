<?php

namespace App\Actions\Accounts;

use App\Models\AuditLog;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

final class RevealTeacherTemporaryCredential
{
    /** @return array{password: string, expires_at: string} */
    public function execute(User $actor, Teacher $teacher): array
    {
        return DB::transaction(function () use ($actor, $teacher): array {
            [$actor, $target] = AccountSecurity::lockPair($actor, $teacher->user);
            AccountSecurity::admin($actor);
            abort_unless($target->role === 'teacher' && $target->account_status === 'pending_activation' && $target->must_change_password, 404, 'Data tidak ditemukan.');
            $credential = $target->temporaryCredentials()->whereNull('consumed_at')->first();
            abort_unless($credential, 404, 'Data tidak ditemukan.');
            if ($credential->expires_at->lessThanOrEqualTo(now())) {
                throw new GoneHttpException('Password sementara sudah kedaluwarsa.');
            }

            AuditLog::create([
                'actor_user_id' => $actor->id,
                'action' => 'teacher_temporary_credential_revealed',
                'resource_type' => 'user',
                'resource_id' => $target->id,
                'safe_after' => ['role' => 'teacher', 'account_status' => 'pending_activation'],
                'request_id' => request()->attributes->get('request_id') ?? (string) Str::uuid(),
            ]);

            return ['password' => $credential->encrypted_secret, 'expires_at' => $credential->expires_at->toISOString()];
        });
    }
}
