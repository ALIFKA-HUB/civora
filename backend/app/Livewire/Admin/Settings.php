<?php

namespace App\Livewire\Admin;

use App\Actions\Accounts\CreateAccount;
use App\Actions\Accounts\DeactivateAccount;
use App\Livewire\Admin\Concerns\RequiresRecentAdminConfirmation;
use App\Models\AuditLog;
use App\Models\SchoolSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Settings extends Component
{
    use RequiresRecentAdminConfirmation;

    public string $schoolName = '';

    public string $timezone = 'Asia/Jakarta';

    public string $adminName = '';

    public string $username = '';

    public ?string $targetAdminId = null;

    public string $reason = '';

    public string $message = '';

    public function mount(): void
    {
        $settings = SchoolSetting::query()->first();
        $this->schoolName = $settings?->name ?? 'Civora';
        $this->timezone = $settings?->timezone ?? 'Asia/Jakarta';
    }

    public function saveSchool(): void
    {
        $this->requireRecentAdminConfirmation();
        $values = $this->validate([
            'schoolName' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
        ]);

        DB::transaction(function () use ($values): void {
            DB::select('SELECT pg_advisory_xact_lock(20261013, 5)');
            $settings = SchoolSetting::query()->lockForUpdate()->first();
            $before = $settings ? ['name' => $settings->name, 'timezone' => $settings->timezone] : [];
            if ($settings) {
                $settings->update(['name' => trim($values['schoolName']), 'timezone' => $values['timezone']]);
            } else {
                $settings = SchoolSetting::create(['name' => trim($values['schoolName']), 'timezone' => $values['timezone']]);
            }
            AuditLog::create([
                'actor_user_id' => auth('web')->id(), 'action' => 'school_settings_updated', 'resource_type' => 'school_settings',
                'resource_id' => $settings->id, 'safe_before' => $before,
                'safe_after' => ['name' => $settings->name, 'timezone' => $settings->timezone],
                'request_id' => request()->attributes->get('request_id') ?? (string) Str::uuid(),
            ]);
        });
        $this->message = 'Pengaturan sekolah disimpan.';
    }

    public function createAdmin(): void
    {
        $this->requireRecentAdminConfirmation();
        $values = $this->validate([
            'adminName' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9._-]+$/'],
        ]);
        app(CreateAccount::class)->execute(auth('web')->user(), ['role' => 'admin', 'name' => $values['adminName'], 'username' => $values['username']]);
        $this->reset('adminName', 'username');
        $this->message = 'Akun admin dibuat dan menunggu aktivasi.';
    }

    public function deactivateAdmin(): void
    {
        $this->requireRecentAdminConfirmation();
        $values = $this->validate([
            'targetAdminId' => ['required', 'uuid', Rule::exists('users', 'id')->where('role', 'admin')],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        abort_if($values['targetAdminId'] === auth('web')->id(), 403, 'Admin tidak dapat menonaktifkan akunnya sendiri.');
        app(DeactivateAccount::class)->execute(auth('web')->user(), User::findOrFail($values['targetAdminId']), $values['reason']);
        $this->reset('targetAdminId', 'reason');
        $this->message = 'Akun admin dinonaktifkan dan tindakannya dicatat.';
    }

    public function render()
    {
        $admins = User::query()->where('role', 'admin')->with('loginIdentifiers')->orderBy('name')->paginate(10);
        $timezones = timezone_identifiers_list();

        return view('livewire.admin.settings', compact('admins', 'timezones'), ['title' => 'Pengaturan']);
    }
}
