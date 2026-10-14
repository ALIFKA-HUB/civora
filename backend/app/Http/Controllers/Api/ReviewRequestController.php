<?php

namespace App\Http\Controllers\Api;

use App\Actions\Reviews\SubmitReviewRequest;
use App\Http\Controllers\Controller;
use App\Models\PointRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ReviewRequestController extends Controller
{
    public function object(Request $request, PointRecord $record, SubmitReviewRequest $submit): JsonResponse
    {
        abort_unless($request->user()->role === 'student' && $record->student?->user_id === $request->user()->id, 404);
        if (array_diff(array_keys($request->all()), ['reason']) !== []) {
            throw ValidationException::withMessages(['body' => 'Keberatan siswa hanya menerima alasan.']);
        }
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $review = $submit->execute($request->user(), $record, 'student_objection', $data['reason'], []);

        return response()->json(['data' => $this->resource($review)], 201);
    }

    public function requestCorrection(Request $request, PointRecord $record, SubmitReviewRequest $submit): JsonResponse
    {
        abort_unless($request->user()->role === 'teacher' && $record->teacher?->user_id === $request->user()->id, 404);
        if (array_diff(array_keys($request->all()), ['reason', 'proposed_change']) !== []) {
            throw ValidationException::withMessages(['body' => 'Field permintaan koreksi tidak diizinkan.']);
        }
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'proposed_change' => ['required', 'array'],
        ]);
        $review = $submit->execute($request->user(), $record, 'teacher_correction', $data['reason'], $data['proposed_change']);

        return response()->json(['data' => $this->resource($review)], 201);
    }

    private function resource($review): array
    {
        return [
            'id' => $review->id,
            'record_id' => $review->point_record_id,
            'kind' => $review->kind,
            'reason' => $review->reason,
            'proposed_change' => $review->proposed_change,
            'status' => $review->status,
            'reviewed_revision' => $review->reviewed_revision,
            'created_at' => $review->created_at?->toISOString(),
        ];
    }
}
