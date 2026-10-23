<?php

namespace App\Actions\Accounts;

use App\Models\LoginIdentifier;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreateAccount
{
    public function execute(User $admin, array $attributes): User
    {
        AccountSecurity::admin($admin->fresh());
        $data = Validator::make($attributes, [
            'role' => ['required', Rule::in(['student', 'teacher', 'admin'])],
            'name' => ['required', 'string', 'max:255'],
            'nisn' => ['required_if:role,student', 'string', 'regex:/^[0-9]+$/', 'max:30'],
            'nip' => ['nullable', 'string', 'regex:/^[0-9]+$/', 'max:30'],
            'teacher_code' => ['required_if:role,teacher', 'string', 'max:30'],
            'username' => ['required_if:role,admin', 'string', 'max:255'],
        ], ['required' => 'Isian :attribute wajib diisi.', 'required_if' => 'Isian :attribute wajib diisi.', 'string' => 'Isian :attribute harus berupa teks.', 'regex' => 'Format :attribute tidak valid.', 'max' => 'Isian :attribute terlalu panjang.', 'in' => 'Pilihan :attribute tidak valid.'])->validate();

        try {
            return DB::transaction(function () use ($admin, $data): User {
                AccountSecurity::lockAdminChanges();
                $admin = User::whereKey($admin->id)->lockForUpdate()->firstOrFail();
                AccountSecurity::admin($admin);
                $role = $data['role'];
                $aliases = match ($role) {
                    'student' => ['nisn' => $data['nisn']],
                    'teacher' => array_filter(['teacher_code' => $data['teacher_code'], 'nip' => $data['nip'] ?? null], fn ($value) => $value !== null && $value !== ''),
                    'admin' => ['username' => $data['username']],
                };
                $normalized = array_map(fn ($value) => mb_strtolower(trim($value)), $aliases);
                if (in_array('', $normalized, true) || count(array_unique($normalized)) !== count($normalized)
                    || LoginIdentifier::where('role', $role)->whereIn('normalized_value', $normalized)->exists()) {
                    throw ValidationException::withMessages(['identifier' => 'Identitas login sudah digunakan atau ambigu.']);
                }
                $user = User::create(['role' => $role, 'name' => trim($data['name']), 'password_hash' => Str::password(24), 'account_status' => 'pending_activation', 'must_change_password' => true]);
                foreach ($normalized as $kind => $value) {
                    $user->loginIdentifiers()->create(['role' => $role, 'kind' => $kind, 'normalized_value' => $value]);
                }
                if ($role === 'student') {
                    Student::create(['user_id' => $user->id, 'nisn' => $data['nisn'], 'education_status' => 'active']);
                } elseif ($role === 'teacher') {
                    Teacher::create(['user_id' => $user->id, 'teacher_code' => trim($data['teacher_code']), 'nip' => $data['nip'] ?? null]);
                }
                AccountSecurity::temporary($admin, $user, 'Pembuatan akun awal');
                AccountSecurity::audit($admin, $user, 'account_created');

                return $user;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['identifier' => 'Identitas login sudah digunakan.']);
        }
    }
}
