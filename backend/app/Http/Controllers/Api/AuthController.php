<?php

namespace App\Http\Controllers\Api;

use App\Actions\Accounts\AccountSecurity;
use App\Actions\Accounts\ActivateAccount;
use App\Actions\Accounts\RevealTemporaryCredential;
use App\Http\Controllers\Controller;
use App\Models\LoginIdentifier;
use App\Models\QrToken;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    private static ?string $dummyPasswordHash = null;

    public function studentLogin(Request $request): JsonResponse
    {
        $data = $request->validate(['nisn' => ['required', 'string', 'max:30'], 'password' => ['required', 'string', 'max:1024']]);

        return $this->login('student', $data['nisn'], $data['password']);
    }

    public function teacherLogin(Request $request): JsonResponse
    {
        $data = $request->validate(['identifier' => ['required', 'string', 'max:30'], 'password' => ['required', 'string', 'max:1024']]);

        return $this->login('teacher', $data['identifier'], $data['password']);
    }

    private function login(string $role, string $identifier, string $password): JsonResponse
    {
        return DB::transaction(function () use ($role, $identifier, $password): JsonResponse {
            self::$dummyPasswordHash ??= Hash::make(Str::random(32));
            $alias = LoginIdentifier::where('role', $role)->where('normalized_value', mb_strtolower(trim($identifier)))->first();
            $user = $alias ? User::whereKey($alias->user_id)->lockForUpdate()->first() : null;
            // A non-account hash does comparable password work for unknown identities.
            $validPassword = Hash::check($password, $user?->password_hash ?? self::$dummyPasswordHash);
            $pending = $user && ($user->must_change_password || $user->account_status === 'pending_activation');
            $validTemporary = ! $pending || $user->temporaryCredentials()->whereNull('consumed_at')->where('expires_at', '>', now())->exists();
            if (! $user || $user->role !== $role || ! $validPassword || $user->account_status === 'inactive' || ! $validTemporary
                || ($role === 'student' && (! $user->student || $user->student->education_status !== 'active'))
                || ($role === 'teacher' && ! $user->teacher)) {
                return response()->json(['error' => ['code' => 'INVALID_CREDENTIALS', 'message' => 'Identitas login atau password tidak valid.'], 'request_id' => request()->attributes->get('request_id')], 401);
            }
            $expires = now()->addDays(7);
            $token = $user->createToken($role.'-mobile', ['*'], $expires);

            return response()->json(['data' => ['token' => $token->plainTextToken, 'token_type' => 'Bearer', 'expires_at' => $expires->toISOString(), ...$this->identity($user)]])->header('Cache-Control', 'no-store, private');
        });
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->identity($user);
        if (! $user->must_change_password && $user->account_status === 'active' && $user->role === 'student') {
            $data['student'] = $user->student?->only(['id', 'nisn', 'education_status']);
            $data['enrollments'] = $user->student?->enrollments()->with('semester', 'classroom.major')->get();
        } elseif (! $user->must_change_password && $user->account_status === 'active' && $user->role === 'teacher') {
            $data['teacher'] = $user->teacher?->only(['id', 'nip', 'teacher_code']);
        }

        return response()->json(['data' => $data])->header('Cache-Control', 'no-store, private');
    }

    public function changePassword(Request $request, ActivateAccount $activate): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string', 'max:1024'], 'password' => ['required', 'string', 'min:12', 'max:1024', 'confirmed', 'different:current_password']]);

        return DB::transaction(function () use ($request, $activate, $data): JsonResponse {
            if ($request->user()->role === 'admin') {
                AccountSecurity::lockAdminChanges();
            }
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $this->confirmPassword($user, $data['current_password']);
            $activate->execute($user, $data['password']);

            return response()->json(['data' => ['message' => 'Password berhasil diganti. Silakan login kembali.']]);
        });
    }

    public function logout(Request $request): JsonResponse
    {
        DB::transaction(function () use ($request): void {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $token = $request->user()->currentAccessToken();
            abort_unless($token instanceof PersonalAccessToken, 401, 'Sesi tidak sah.');
            $user->deviceTokens()->where('personal_access_token_id', $token->id)->update(['active' => false]);
            QrToken::where('session_id', $token->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $token->delete();
        });

        return response()->json(['data' => ['message' => 'Logout berhasil.']]);
    }

    public function reveal(Request $request, Student $student, RevealTemporaryCredential $reveal): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string', 'max:1024']]);

        return DB::transaction(function () use ($request, $student, $data, $reveal): JsonResponse {
            [$actor] = AccountSecurity::lockPair($request->user(), $student->user);
            $this->confirmPassword($actor, $data['current_password']);

            return response()->json(['data' => $reveal->execute($actor, $student)])->header('Cache-Control', 'no-store, private');
        });
    }

    private function confirmPassword(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password_hash)) {
            throw ValidationException::withMessages(['current_password' => 'Password saat ini tidak valid.']);
        }
    }

    private function identity(User $user): array
    {
        return $user->only(['id', 'name', 'role', 'account_status', 'must_change_password']);
    }
}
