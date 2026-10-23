<?php

namespace App\Actions\Accounts;

use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

class RevealTemporaryCredential
{
    public function execute(User $actor, Student $student): array
    {
        return DB::transaction(function () use ($actor, $student): array {
            [$actor, $target] = AccountSecurity::lockPair($actor, $student->user);
            $student = $student->fresh();
            $student->setRelation('user', $target);
            Gate::forUser($actor)->authorize('revealTemporaryCredential', $student);
            abort_unless($target->account_status === 'pending_activation' && $target->must_change_password, 404, 'Data tidak ditemukan.');
            $credential = $target->temporaryCredentials()->whereNull('consumed_at')->first();
            abort_unless($credential, 404, 'Data tidak ditemukan.');
            if ($credential->expires_at->lessThanOrEqualTo(now())) {
                throw new GoneHttpException('Password sementara sudah kedaluwarsa.');
            }
            AccountSecurity::audit($actor, $target, 'temporary_credential_revealed');

            return ['password' => $credential->encrypted_secret, 'expires_at' => $credential->expires_at->toISOString()];
        });
    }
}
