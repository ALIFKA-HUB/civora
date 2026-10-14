<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PointRecordResource;
use App\Models\PointRecord;
use App\Models\Student;
use App\Models\Teacher;
use App\Policies\StudentPolicy;
use App\Support\HistorySemesters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TeacherRecordController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'teacher', 403, 'Akses ditolak.');
        $filters = $this->filters($request);
        $teacherId = Teacher::where('user_id', $request->user()->id)->value('id');
        abort_unless($teacherId, 403, 'Akses ditolak.');
        $historyScope = PointRecord::where('teacher_id', $teacherId);
        $query = PointRecord::with(['latestRevision', 'student.user', 'teacher.user', 'semester'])->where('teacher_id', $teacherId);
        $this->filter($query, $filters);
        $page = $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 20);

        return $this->page($page, $historyScope);
    }

    public function show(Request $request, PointRecord $record): JsonResponse
    {
        abort_unless($request->user()->role === 'teacher', 403, 'Akses ditolak.');
        $ownRecord = $record->teacher?->user_id === $request->user()->id;
        $eligibleStudent = (new StudentPolicy)->view($request->user(), $record->student);
        abort_unless($ownRecord || $eligibleStudent, 404);

        return response()->json(['data' => (new PointRecordResource($record->load(['latestRevision', 'student.user', 'teacher.user', 'semester'])))->resolve()]);
    }

    public function studentRecords(Request $request, string $id): JsonResponse
    {
        abort_unless($request->user()->role === 'teacher', 403, 'Akses ditolak.');
        $student = Student::with('user')->whereKey($id)->firstOrFail();
        abort_unless((new StudentPolicy)->view($request->user(), $student), 404);
        $filters = $this->filters($request);
        $historyScope = PointRecord::where('student_id', $student->id);
        $query = PointRecord::with(['latestRevision', 'student.user', 'teacher.user', 'semester'])->where('student_id', $student->id);
        $this->filter($query, $filters);
        $page = $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 20);

        return $this->page($page, $historyScope);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'semester_id' => ['nullable', 'uuid'],
            'category' => ['nullable', 'in:achievement,violation'],
            'status' => ['nullable', 'in:active,cancelled'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    private function filter($query, array $filters): void
    {
        if (isset($filters['semester_id'])) {
            $query->where('semester_id', $filters['semester_id']);
        }
        if (isset($filters['category'])) {
            $query->whereHas('latestRevision', fn ($revision) => $revision->where('category_snapshot', $filters['category']));
        }
        if (isset($filters['status'])) {
            $query->whereHas('latestRevision', fn ($revision) => $revision->where('status', $filters['status']));
        }
    }

    private function page($page, $historyScope): JsonResponse
    {
        return response()->json([
            'data' => PointRecordResource::collection($page->getCollection())->resolve(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'available_semesters' => HistorySemesters::forRecords($historyScope)],
        ]);
    }
}
