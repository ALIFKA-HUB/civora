<?php

namespace App\Livewire\Admin;

use App\Actions\Academic\EnrollStudent;
use App\Actions\Academic\OpenSemester;
use App\Livewire\Admin\Concerns\RequiresRecentAdminConfirmation;
use App\Models\Classroom;
use App\Models\Semester;
use App\Models\Student;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Academic extends Component
{
    use RequiresRecentAdminConfirmation;

    public string $studentId = '';

    public string $semesterId = '';

    public string $classroomId = '';

    public string $effectiveAt = '';

    public string $message = '';

    public function mount(): void
    {
        $this->effectiveAt = now()->utc()->toIso8601String();
    }

    public function enroll(): void
    {
        $this->requireRecentAdminConfirmation();
        $data = $this->validate([
            'studentId' => ['required', 'uuid', Rule::exists('students', 'id')],
            'semesterId' => ['required', 'uuid', Rule::exists('semesters', 'id')],
            'classroomId' => ['required', 'uuid', Rule::exists('classrooms', 'id')],
            'effectiveAt' => ['required', 'date'],
        ]);
        app(EnrollStudent::class)->execute(
            auth('web')->user(), Student::findOrFail($data['studentId']), Semester::findOrFail($data['semesterId']), $data['classroomId'], $data['effectiveAt'],
        );
        $this->reset('studentId', 'classroomId');
        $this->message = 'Penempatan siswa disiapkan dan diaudit.';
    }

    public function openSemester(string $semesterId): void
    {
        $this->requireRecentAdminConfirmation();
        $this->semesterId = $semesterId;
        $this->validate(['semesterId' => ['required', 'uuid', Rule::exists('semesters', 'id')]]);
        app(OpenSemester::class)->execute(auth('web')->user(), Semester::findOrFail($semesterId));
        $this->message = 'Semester dibuka. Penempatan siswa telah diperiksa.';
    }

    public function render()
    {
        $semesters = Semester::query()->with(['academicYear', 'enrollments'])->orderByDesc('start_date')->get();
        $students = Student::query()->with('user')->where('education_status', 'active')->orderBy('nisn')->get();
        $classrooms = Classroom::query()->with(['major', 'academicYear'])->where('active', true)->orderBy('grade')->orderBy('name')->get();

        return view('livewire.admin.academic', compact('semesters', 'students', 'classrooms'), ['title' => 'Akademik']);
    }
}
