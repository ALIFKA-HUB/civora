<?php

namespace App\Http\Controllers\Api;

use App\Actions\Points\StudentSelectionRules;
use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\Major;
use App\Models\Semester;
use App\Models\Student;
use App\Services\Academic\SchoolCalendar;
use App\Services\Points\PointTotals;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TeacherStudentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        StudentSelectionRules::teacher($request->user());
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'classroom_id' => ['nullable', 'uuid'],
            'major_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $semester = $this->currentSemester();
        $now = CarbonImmutable::now();
        $query = Student::query()
            ->join('users', 'users.id', '=', 'students.user_id')
            ->join('enrollments', function ($join) use ($semester, $now): void {
                $join->on('enrollments.student_id', '=', 'students.id')->where('enrollments.semester_id', $semester->id)
                    ->where('enrollments.valid_from', '<=', $now)
                    ->where(fn ($range) => $range->whereNull('enrollments.valid_until')->orWhere('enrollments.valid_until', '>', $now));
            })
            ->join('classrooms', 'classrooms.id', '=', 'enrollments.classroom_id')
            ->join('majors', 'majors.id', '=', 'classrooms.major_id')
            ->where('students.education_status', 'active')
            ->whereIn('users.account_status', ['active', 'pending_activation'])
            ->where('users.role', 'student')
            ->where('classrooms.academic_year_id', $semester->academic_year_id)
            ->where('classrooms.active', true)->where('majors.active', true);
        if (! empty($filters['q'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['q']).'%';
            $query->where(fn ($where) => $where->where('users.name', 'ilike', $term)->orWhere('students.nisn', 'ilike', $term));
        }
        if (! empty($filters['classroom_id'])) {
            $query->where('classrooms.id', $filters['classroom_id']);
        }
        if (! empty($filters['major_id'])) {
            $query->where('majors.id', $filters['major_id']);
        }
        $page = $query->select([
            'students.id', 'users.name', 'students.nisn', 'classrooms.id as classroom_id', 'classrooms.name as classroom_name',
            'majors.id as major_id', 'majors.name as major_name',
        ])->orderBy('users.name')->orderBy('students.id')->paginate($filters['per_page'] ?? 20);
        $classrooms = Classroom::where('academic_year_id', $semester->academic_year_id)->where('active', true)
            ->whereHas('major', fn ($query) => $query->where('active', true))
            ->orderBy('grade')->orderBy('code')->get(['id', 'name']);
        $majors = Major::where('active', true)
            ->whereHas('classrooms', fn ($query) => $query->where('academic_year_id', $semester->academic_year_id)->where('active', true))
            ->orderBy('name')->get(['id', 'name', 'code']);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($student) => [
                'id' => $student->id, 'name' => $student->name, 'nisn' => $student->nisn,
                'classroom' => ['id' => $student->classroom_id, 'name' => $student->classroom_name],
                'major' => ['id' => $student->major_id, 'name' => $student->major_name],
            ]),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                'filters' => [
                    'classrooms' => $classrooms->map(fn ($classroom) => ['id' => $classroom->id, 'name' => $classroom->name])->all(),
                    'majors' => $majors->map(fn ($major) => ['id' => $major->id, 'name' => $major->name, 'code' => $major->code])->all(),
                ],
            ],
        ]);
    }

    public function show(Request $request, string $id, PointTotals $totals): JsonResponse
    {
        StudentSelectionRules::teacher($request->user());
        $student = Student::whereKey($id)->first();
        if (! $student) {
            abort(404);
        }
        $student = StudentSelectionRules::student($student);
        $semester = $this->currentSemester();
        $enrollment = StudentSelectionRules::enrollment($student, $semester);
        $classroom = $enrollment->classroom;
        $major = $classroom->major;

        return response()->json(['data' => [
            'id' => $student->id,
            'name' => $student->user->name,
            'nisn' => $student->nisn,
            'account_status' => $student->user->account_status,
            'must_change_password' => $student->user->must_change_password,
            'semester' => ['id' => $semester->id, 'name' => $semester->name],
            'enrollment' => ['id' => $enrollment->id, 'classroom' => ['id' => $classroom->id, 'name' => $classroom->name], 'major' => ['id' => $major->id, 'name' => $major->name]],
            'totals' => $totals->forStudent($student, $semester),
        ]]);
    }

    private function currentSemester(): Semester
    {
        $semester = Semester::where('status', 'active')->first();
        if (! $semester || ! (new SchoolCalendar)->contains($semester, CarbonImmutable::now())) {
            throw ValidationException::withMessages(['semester' => 'Tidak ada semester aktif untuk pencatatan.']);
        }

        return $semester;
    }
}
