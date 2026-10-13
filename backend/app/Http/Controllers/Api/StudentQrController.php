<?php

namespace App\Http\Controllers\Api;

use App\Actions\Points\IssueStudentQr;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentQrController extends Controller
{
    public function store(Request $request, IssueStudentQr $issue): JsonResponse
    {
        abort_unless($request->user()->role === 'student', 403, 'Akses ditolak.');

        return response()->json(['data' => $issue->execute($request->user())], 200, [
            'Cache-Control' => 'no-store, private, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
