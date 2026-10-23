<?php

namespace App\Http\Controllers\Api;

use App\Actions\Points\RecordPoint;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePointRecordRequest;
use App\Http\Resources\PointRecordResource;
use App\Models\PointRecord;
use App\Models\Teacher;
use App\Services\Points\PointTotals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PointRecordController extends Controller
{
    public function store(StorePointRecordRequest $request, RecordPoint $record): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key', '');
        $pointRecord = $record->execute($request->user(), $request->validated(), $key);
        $created = $pointRecord->wasRecentlyCreated;
        $totals = app(PointTotals::class)->forStudent($pointRecord->student, $pointRecord->semester);

        return response()->json([
            'data' => (new PointRecordResource($pointRecord))->resolve(),
            'summary' => ['semester_id' => $pointRecord->semester_id, ...$totals],
        ], $created ? 201 : 200, ['Cache-Control' => 'no-store, private']);
    }

    public function attempt(Request $request, string $key): JsonResponse
    {
        abort_unless($request->user()->role === 'teacher', 403, 'Akses ditolak.');
        $teacherId = Teacher::where('user_id', $request->user()->id)->value('id');
        $record = PointRecord::with(['latestRevision', 'student.user', 'teacher.user', 'semester'])
            ->where('teacher_id', $teacherId)->where('idempotency_key', $key)->firstOrFail();
        $totals = app(PointTotals::class)->forStudent($record->student, $record->semester);

        return response()->json([
            'data' => (new PointRecordResource($record))->resolve(),
            'summary' => ['semester_id' => $record->semester_id, ...$totals],
        ], 200, ['Cache-Control' => 'no-store, private']);
    }
}
