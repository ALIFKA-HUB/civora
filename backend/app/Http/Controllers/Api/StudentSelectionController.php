<?php

namespace App\Http\Controllers\Api;

use App\Actions\Points\RedeemStudentQr;
use App\Actions\Points\SelectStudentManually;
use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentSelectionController extends Controller
{
    public function redeem(Request $request, RedeemStudentQr $redeem): JsonResponse
    {
        abort_unless($request->user()->role === 'teacher', 403, 'Akses ditolak.');
        $values = $request->validate(['token' => ['required', 'string', 'max:100'], 'request_key' => ['required', 'string', 'max:100']]);
        $selection = $redeem->execute($request->user(), $values['token'], $values['request_key']);

        return response()->json(['data' => $selection], 201, ['Cache-Control' => 'no-store, private, max-age=0']);
    }

    public function store(Request $request, SelectStudentManually $select): JsonResponse
    {
        abort_unless($request->user()->role === 'teacher', 403, 'Akses ditolak.');
        $values = $request->validate(['student_id' => ['required', 'uuid']]);
        $student = Student::whereKey($values['student_id'])->firstOrFail();

        return response()->json(['data' => $select->execute($request->user(), $student)], 201);
    }
}
