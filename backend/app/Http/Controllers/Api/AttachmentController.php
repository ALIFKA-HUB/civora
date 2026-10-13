<?php

namespace App\Http\Controllers\Api;

use App\Actions\Points\StageEvidence;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class AttachmentController extends Controller
{
    public function store(Request $request, StageEvidence $stage): JsonResponse
    {
        abort_unless($request->user()->role === 'teacher', 403, 'Akses ditolak.');
        abort_if(array_diff(array_keys($request->all()), ['file']) !== [], 422, 'Field bukti tidak diizinkan.');
        $values = $request->validate(['file' => ['required', 'file', 'max:5120']]);
        $attachment = $stage->execute($request->user(), $values['file']);

        return response()->json(['data' => ['id' => $attachment->id, 'mime_type' => $attachment->mime_type, 'size' => $attachment->size]], 201, ['Cache-Control' => 'no-store, private']);
    }

    public function show(Request $request, string $id)
    {
        $attachment = Attachment::whereKey($id)->firstOrFail();
        $user = $request->user();
        $allowed = $user->role === 'teacher' && $attachment->owner_user_id === $user->id;
        if (! $allowed) {
            $allowed = $attachment->recordRevisions()->whereHas('pointRecord', function ($query) use ($user): void {
                if ($user->role === 'teacher') {
                    $query->whereHas('teacher', fn ($teacher) => $teacher->where('user_id', $user->id));
                } elseif ($user->role === 'student') {
                    $query->whereHas('student', fn ($student) => $student->where('user_id', $user->id));
                } else {
                    $query->whereRaw('1 = 0');
                }
            })->exists();
        }
        abort_unless($allowed, 404, 'Data tidak ditemukan.');
        abort_unless(Storage::disk('local')->exists($attachment->private_path), 404, 'Data tidak ditemukan.');
        $stream = Storage::disk('local')->readStream($attachment->private_path);
        abort_unless(is_resource($stream), 404, 'Data tidak ditemukan.');

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $attachment->mime_type,
            'Content-Length' => (string) Storage::disk('local')->size($attachment->private_path),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
