<?php

namespace App\Livewire\Admin;

use App\Actions\Accounts\CreateAccount;
use App\Actions\Accounts\DeactivateAccount;
use App\Actions\Accounts\ResetAccountPassword;
use App\Livewire\Admin\Concerns\RequiresRecentAdminConfirmation;
use App\Models\Student;
use App\Models\User;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class Students extends Component
{
    use RequiresRecentAdminConfirmation, WithPagination;

    public string $search = '';

    public string $status = '';

    public string $name = '';

    public string $nisn = '';

    public ?string $targetUserId = null;

    public string $reason = '';

    public string $message = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'nisn' => ['required', 'string', 'regex:/^[0-9]+$/', 'max:30'],
        ]);

        app(CreateAccount::class)->execute(auth('web')->user(), ['role' => 'student', ...$data]);
        $this->reset('name', 'nisn');
        $this->message = 'Akun siswa dibuat. Password sementara berlaku 24 jam.';
    }

    public function resetPassword(): void
    {
        $this->requireRecentAdminConfirmation();
        $data = $this->validate([
            'targetUserId' => ['required', 'uuid', Rule::exists('users', 'id')->where('role', 'student')],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        app(ResetAccountPassword::class)->execute(auth('web')->user(), User::findOrFail($data['targetUserId']), $data['reason']);
        $this->reset('reason', 'targetUserId');
        $this->message = 'Password sementara baru dibuat dan sesi lama dicabut.';
    }

    public function deactivate(): void
    {
        $this->requireRecentAdminConfirmation();
        $data = $this->validate([
            'targetUserId' => ['required', 'uuid', Rule::exists('users', 'id')->where('role', 'student')],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        app(DeactivateAccount::class)->execute(auth('web')->user(), User::findOrFail($data['targetUserId']), $data['reason']);
        $this->reset('reason', 'targetUserId');
        $this->message = 'Akun siswa dinonaktifkan. Riwayat sekolah tetap tersedia.';
    }

    public function render()
    {
        $students = Student::query()->with(['user.loginIdentifiers', 'enrollments' => fn ($query) => $query->with(['classroom', 'semester'])->orderByDesc('valid_from')])
            ->when(trim($this->search) !== '', function ($query): void {
                $term = '%'.mb_strtolower(trim($this->search)).'%';
                $query->where(function ($matches) use ($term): void {
                    $matches->whereHas('user', fn ($user) => $user->whereRaw('lower(name) like ?', [$term]))
                        ->orWhereRaw('lower(nisn) like ?', [$term]);
                });
            })
            ->when($this->status === 'pending', fn ($query) => $query->whereHas('user', fn ($user) => $user->where('account_status', 'pending_activation')))
            ->when($this->status === 'inactive', fn ($query) => $query->whereHas('user', fn ($user) => $user->where('account_status', 'inactive')))
            ->when(in_array($this->status, ['active', 'transferred', 'graduated'], true), fn ($query) => $query->where('education_status', $this->status))
            ->orderBy('nisn')->paginate(15);

        return view('livewire.admin.students', compact('students'), ['title' => 'Siswa']);
    }
}
