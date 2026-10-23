<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PointRecordResource;
use App\Models\PointRecord;
use App\Support\HistorySemesters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StudentRecordController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'student', 403, 'Akses ditolak.');
        $filters = $request->validate([
            'semester_id' => ['nullable', 'uuid'],
            'category' => ['nullable', 'in:achievement,violation'],
            'status' => ['nullable', 'in:active,cancelled'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $studentId = $request->user()->student?->id;
        abort_unless($studentId, 404);
        $historyScope = PointRecord::where('student_id', $studentId);
        $query = PointRecord::with(['latestRevision', 'student.user', 'teacher.user', 'semester'])
            ->where('student_id', $studentId);
        $this->filter($query, $filters);
        $page = $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 20);

        return response()->json([
            'data' => PointRecordResource::collection($page->getCollection())->resolve(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'available_semesters' => HistorySemesters::forRecords($historyScope)],
        ]);
    }

    public function show(Request $request, PointRecord $record): JsonResponse
    {
        abort_unless($request->user()->role === 'student' && $record->student?->user_id === $request->user()->id, 404);

        return response()->json(['data' => (new PointRecordResource($record->load(['latestRevision', 'student.user', 'teacher.user', 'semester'])))->resolve()]);
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
}
