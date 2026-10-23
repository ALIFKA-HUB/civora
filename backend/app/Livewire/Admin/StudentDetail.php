<?php

namespace App\Livewire\Admin;

use App\Actions\Academic\ChangeStudentStatus;
use App\Actions\Academic\MoveStudent;
use App\Actions\Accounts\DeactivateAccount;
use App\Actions\Accounts\ResetAccountPassword;
use App\Actions\Accounts\RevealTemporaryCredential;
use App\Livewire\Admin\Concerns\RequiresRecentAdminConfirmation;
use App\Models\Classroom;
use App\Models\PointRecord;
use App\Models\Semester;
use App\Models\Student;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

#[Layout('layouts.admin')]
class StudentDetail extends Component
{
    use RequiresRecentAdminConfirmation;

    public string $studentId;

    public string $destinationClassroomId = '';

    public string $effectiveAt = '';

    public string $statusReason = '';

    public string $reason = '';

    public ?string $targetUserId = null;

    public ?string $temporaryPassword = null;

    public string $message = '';

    public function mount(string $studentId): void
    {
        $this->studentId = $studentId;
        $this->effectiveAt = now()->utc()->addMinute()->toIso8601String();
    }

    public function move(): void
    {
        $this->requireRecentAdminConfirmation();
        $data = $this->validate([
            'destinationClassroomId' => ['required', 'uuid', Rule::exists('classrooms', 'id')],
            'effectiveAt' => ['required', 'date'],
        ]);

        app(MoveStudent::class)->execute(auth('web')->user(), $this->student(), $data['destinationClassroomId'], $data['effectiveAt']);
        $this->reset('destinationClassroomId');
        $this->message = 'Perpindahan kelas dicatat. Riwayat dan catatan lama tetap terhubung ke kelas asal.';
    }

    public function graduate(): void
    {
        $this->requireRecentAdminConfirmation();
        $data = $this->validate(['statusReason' => ['required', 'string', 'min:5', 'max:1000']]);
        app(ChangeStudentStatus::class)->execute(auth('web')->user(), $this->student(), 'graduated', $data['statusReason']);
        $this->reset('statusReason');
        $this->message = 'Siswa ditandai lulus dan akunnya dinonaktifkan.';
    }

    public function transfer(): void
    {
        $this->requireRecentAdminConfirmation();
        $data = $this->validate(['statusReason' => ['required', 'string', 'min:5', 'max:1000']]);
        app(ChangeStudentStatus::class)->execute(auth('web')->user(), $this->student(), 'transferred', $data['statusReason']);
        $this->reset('statusReason');
        $this->message = 'Siswa ditandai pindah dan akunnya dinonaktifkan.';
    }

    public function resetPassword(): void
    {
        $this->requireRecentAdminConfirmation();
        $data = $this->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        app(ResetAccountPassword::class)->execute(auth('web')->user(), $this->student()->user, $data['reason']);
        $this->reset('reason');
        $this->message = 'Password sementara baru dibuat dan sesi lama dicabut.';
    }

    public function deactivate(): void
    {
        $this->requireRecentAdminConfirmation();
        $data = $this->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        app(DeactivateAccount::class)->execute(auth('web')->user(), $this->student()->user, $data['reason']);
        $this->reset('reason');
        $this->message = 'Akun siswa dinonaktifkan. Riwayat sekolah tetap tersedia.';
    }

    public function revealTemporaryCredential(): void
    {
        $this->requireRecentAdminConfirmation();
        try {
            $result = app(RevealTemporaryCredential::class)->execute(auth('web')->user(), $this->student());
        } catch (GoneHttpException) {
            throw ValidationException::withMessages(['credential' => 'Password sementara sudah kedaluwarsa. Buat reset baru untuk membagikan kredensial.']);
        }
        $this->temporaryPassword = $result['password'];
        $this->message = 'Password sementara ditampilkan. Berlaku sampai '.$result['expires_at'].'.';
    }

    public function hideTemporaryCredential(): void
    {
        $this->temporaryPassword = null;
    }

    private function student(): Student
    {
        return Student::with('user')->findOrFail($this->studentId);
    }

    public function render()
    {
        $student = Student::with(['user', 'enrollments' => fn ($query) => $query->with(['classroom.major', 'semester.academicYear'])->orderByDesc('valid_from')])->findOrFail($this->studentId);
        $records = PointRecord::query()->with(['latestRevision', 'teacher.user', 'semester.academicYear'])
            ->where('student_id', $student->id)->orderByDesc('occurred_at')->limit(50)->get();
        $classrooms = Classroom::query()->with(['major', 'academicYear'])->where('academic_year_id', $this->activeSemester()?->academic_year_id)
            ->where('active', true)->orderBy('grade')->orderBy('name')->get();
        $currentSemester = $this->activeSemester();

        return view('livewire.admin.student-detail', compact('student', 'records', 'classrooms', 'currentSemester'), ['title' => 'Detail siswa']);
    }

    private function activeSemester(): ?Semester
    {
        return Semester::query()->where('status', 'active')->first();
    }
}
