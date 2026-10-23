<?php

namespace App\Console\Commands;

use App\Actions\Accounts\AccountSecurity;
use App\Models\LoginIdentifier;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateAdmin extends Command
{
    protected $signature = 'civora:create-admin {username} {--name= : Nama admin}';

    protected $description = 'Membuat admin aktif melalui bootstrap lokal dengan password tersembunyi';

    public function handle(): int
    {
        $password = $this->secret('Password admin (minimal 12 karakter)');
        $confirmation = $this->secret('Ulangi password admin');
        try {
            $data = Validator::make([
                'username' => mb_strtolower(trim($this->argument('username'))),
                'name' => trim($this->option('name') ?: $this->argument('username')),
                'password' => $password,
                'password_confirmation' => $confirmation,
            ], ['username' => ['required', 'string', 'max:255'], 'name' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'min:12', 'max:1024', 'confirmed']])->validate();
            DB::transaction(function () use ($data): void {
                AccountSecurity::lockAdminChanges();
                if (LoginIdentifier::where('role', 'admin')->where('normalized_value', $data['username'])->exists()) {
                    throw ValidationException::withMessages(['username' => 'Username sudah digunakan.']);
                }
                $admin = User::create(['role' => 'admin', 'name' => $data['name'], 'password_hash' => $data['password'], 'account_status' => 'active', 'must_change_password' => false]);
                $admin->loginIdentifiers()->create(['role' => 'admin', 'kind' => 'username', 'normalized_value' => $data['username']]);
                AccountSecurity::audit($admin, $admin, 'admin_bootstrapped');
            });
        } catch (ValidationException|UniqueConstraintViolationException $e) {
            $this->error('Admin tidak dibuat. Periksa username dan konfirmasi password (minimal 12 karakter).');

            return self::FAILURE;
        }
        $this->info('Admin berhasil dibuat.');

        return self::SUCCESS;
    }
}
