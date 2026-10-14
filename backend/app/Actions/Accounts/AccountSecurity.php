<?php

namespace App\Actions\Accounts;

use App\Models\AuditLog;
use App\Models\QrToken;
use App\Models\StudentSelection;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AccountSecurity
{
    public static function active(User $user): bool
    {
        return $user->account_status === 'active' && ! $user->must_change_password;
    }

    public static function admin(User $actor): void
    {
        if ($actor->role !== 'admin' || ! self::active($actor)) {
            throw new AuthorizationException('Akses ditolak.');
        }
    }

    public static function lockAdminChanges(): void
    {
        // Serialize admin membership changes to prevent concurrent last-admin write skew.
        DB::select('SELECT pg_advisory_xact_lock(20261012, 2)');
    }

    public static function lockPair(User $actor, User $target): array
    {
        $users = User::whereIn('id', [$actor->id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return [$users[$actor->id], $users[$target->id]];
    }

    public static function protectLastAdmin(User $target): void
    {
        if ($target->role === 'admin' && self::active($target)
            && User::where('role', 'admin')->where('account_status', 'active')->where('must_change_password', false)->count() <= 1) {
            throw ValidationException::withMessages(['account' => 'Admin aktif terakhir harus tetap aktif.']);
        }
    }

    public static function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => 'Alasan harus berisi 5–1.000 karakter.']);
        }

        return $reason;
    }

    public static function temporary(User $actor, User $target, string $reason): void
    {
        $password = Str::password(24);
        $target->update(['password_hash' => $password, 'account_status' => 'pending_activation', 'must_change_password' => true, 'remember_token' => null]);
        $target->temporaryCredentials()->delete();
        $target->temporaryCredentials()->create(['encrypted_secret' => $password, 'expires_at' => now()->addDay(), 'generated_by' => $actor->id, 'reason' => $reason]);
    }

    public static function revoke(User $user): void
    {
        $user->deviceTokens()->update(['active' => false]);
        DB::table('sessions')->where('user_id', $user->id)->delete();
        if ($student = $user->student) {
            QrToken::where('student_id', $student->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            StudentSelection::where('student_id', $student->id)->whereNull('consumed_at')->where('expires_at', '>', now())->update(['expires_at' => now()]);
        }
        if ($teacher = $user->teacher) {
            StudentSelection::where('teacher_id', $teacher->id)->whereNull('consumed_at')->where('expires_at', '>', now())->update(['expires_at' => now()]);
        }
        $user->tokens()->delete();
    }

    public static function audit(User $actor, User $target, string $action, ?string $reason = null): void
    {
        AuditLog::create(['actor_user_id' => $actor->id, 'action' => $action, 'resource_type' => 'user', 'resource_id' => $target->id, 'safe_after' => ['role' => $target->role, 'account_status' => $target->account_status, 'must_change_password' => $target->must_change_password], 'reason' => $reason, 'request_id' => request()->attributes->get('request_id') ?? (string) Str::uuid()]);
    }
}
