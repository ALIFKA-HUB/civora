<?php

namespace App\Livewire\Admin;

use App\Actions\Accounts\CreateAccount;
use App\Actions\Accounts\DeactivateAccount;
use App\Actions\Accounts\ResetAccountPassword;
use App\Actions\Accounts\RevealTeacherTemporaryCredential;
use App\Livewire\Admin\Concerns\RequiresRecentAdminConfirmation;
use App\Models\AuditLog;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

#[Layout('layouts.admin')]
class Teachers extends Component
{
    use RequiresRecentAdminConfirmation, WithPagination;

    public string $search = '';

    public string $status = '';

    public string $name = '';

    public string $teacherCode = '';

    public string $nip = '';

    public ?string $targetUserId = null;

    public string $reason = '';

    public ?string $credentialTeacherId = null;

    public string $credentialAuditSearch = '';

    public ?string $temporaryPassword = null;

    public string $message = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCredentialAuditSearch(): void
    {
        $this->resetPage('credentialAuditsPage');
    }

    public function create(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'teacherCode' => ['required', 'string', 'max:30'],
            'nip' => ['nullable', 'string', 'regex:/^[0-9]+$/', 'max:30'],
        ]);
        app(CreateAccount::class)->execute(auth('web')->user(), [
            'role' => 'teacher', 'name' => $data['name'], 'teacher_code' => $data['teacherCode'], 'nip' => $data['nip'] ?: null,
        ]);
        $this->reset('name', 'teacherCode', 'nip');
        $this->message = 'Akun guru dibuat. Password sementara berlaku 24 jam.';
    }

    public function resetPassword(): void
    {
        $this->requireRecentAdminConfirmation();
        $data = $this->validate([
            'targetUserId' => ['required', 'uuid', Rule::exists('users', 'id')->where('role', 'teacher')],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        app(ResetAccountPassword::class)->execute(auth('web')->user(), User::findOrFail($data['targetUserId']), $data['reason']);
        $this->reset('reason', 'targetUserId');
        $this->message = 'Password sementara guru dibuat. Guru harus mengaktifkannya sebelum masuk.';
    }

    public function deactivate(): void
    {
        $this->requireRecentAdminConfirmation();
        $data = $this->validate([
            'targetUserId' => ['required', 'uuid', Rule::exists('users', 'id')->where('role', 'teacher')],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        app(DeactivateAccount::class)->execute(auth('web')->user(), User::findOrFail($data['targetUserId']), $data['reason']);
        $this->reset('reason', 'targetUserId');
        $this->message = 'Akun guru dinonaktifkan. Catatan yang pernah dibuat tetap tersimpan.';
    }

    public function revealTemporaryCredential(): void
    {
        $this->requireRecentAdminConfirmation();
        $data = $this->validate(['credentialTeacherId' => ['required', 'uuid', Rule::exists('teachers', 'id')]]);
        try {
            $result = app(RevealTeacherTemporaryCredential::class)->execute(auth('web')->user(), Teacher::findOrFail($data['credentialTeacherId']));
        } catch (GoneHttpException) {
            throw ValidationException::withMessages(['credential' => 'Password sementara sudah kedaluwarsa. Buat reset baru untuk membagikan kredensial.']);
        }
        $this->temporaryPassword = $result['password'];
        $this->message = 'Password sementara hanya ditampilkan kepada admin. Berlaku sampai '.$result['expires_at'].'.';
    }

    public function hideTemporaryCredential(): void
    {
        $this->temporaryPassword = null;
    }

    public function render()
    {
        $teachers = Teacher::query()->with('user.loginIdentifiers')
            ->when(trim($this->search) !== '', function ($query): void {
                $term = '%'.mb_strtolower(trim($this->search)).'%';
                $query->where(function ($matches) use ($term): void {
                    $matches->whereRaw('lower(teacher_code) like ?', [$term])
                        ->orWhereRaw('lower(coalesce(nip, \'\')) like ?', [$term])
                        ->orWhereHas('user', fn ($user) => $user->whereRaw('lower(name) like ?', [$term]));
                });
            })
            ->when($this->status !== '', fn ($query) => $query->whereHas('user', fn ($user) => $user->where('account_status', $this->status)))
            ->orderBy('teacher_code')->paginate(15);
        $credentialAudits = AuditLog::query()->select('audit_logs.*', 'target_users.name as target_name')
            ->join('users as target_users', 'target_users.id', '=', 'audit_logs.resource_id')
            ->with('actor')->where('audit_logs.action', 'teacher_temporary_credential_revealed')
            ->when(trim($this->credentialAuditSearch) !== '', function ($query): void {
                $term = '%'.mb_strtolower(trim($this->credentialAuditSearch)).'%';
                $query->where(function ($matches) use ($term): void {
                    $matches->whereRaw('lower(target_users.name) like ?', [$term])
                        ->orWhereHas('actor', fn ($actor) => $actor->whereRaw('lower(name) like ?', [$term]));
                });
            })
            ->orderByDesc('audit_logs.created_at')->paginate(10, ['*'], 'credentialAuditsPage');

        return view('livewire.admin.teachers', compact('teachers', 'credentialAudits'), ['title' => 'Guru']);
    }
}
