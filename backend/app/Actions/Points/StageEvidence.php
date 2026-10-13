<?php

namespace App\Actions\Points;

use App\Actions\Accounts\AccountSecurity;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StageEvidence
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    private const MAX_PIXELS = 20_000_000;

    public function execute(User $teacherUser, UploadedFile $file): Attachment
    {
        $teacherUser = User::whereKey($teacherUser->id)->firstOrFail();
        if ($teacherUser->role !== 'teacher' || ! AccountSecurity::active($teacherUser) || ! $teacherUser->teacher()->exists()) {
            abort(403, 'Akses ditolak.');
        }
        if (! $file->isValid() || $file->getSize() < 1 || $file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => 'Bukti harus berukuran maksimal 5 MB.']);
        }
        $info = @getimagesize($file->getRealPath());
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        if (! $info || ! in_array($mime, ['image/jpeg', 'image/png'], true) || $info['mime'] !== $mime) {
            throw ValidationException::withMessages(['file' => 'Bukti harus berupa JPG atau PNG yang valid.']);
        }
        [$width, $height] = [$info[0], $info[1]];
        if ($width < 1 || $height < 1 || $width > 10000 || $height > 10000 || $width * $height > self::MAX_PIXELS) {
            throw ValidationException::withMessages(['file' => 'Dimensi gambar terlalu besar.']);
        }
        $source = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        if (! $source) {
            throw ValidationException::withMessages(['file' => 'Bukti gambar tidak dapat dibaca.']);
        }
        ob_start();
        $encoded = $mime === 'image/jpeg' ? imagejpeg($source, null, 88) : imagepng($source, null, 6);
        $contents = ob_get_clean();
        imagedestroy($source);
        if (! $encoded || ! is_string($contents) || strlen($contents) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => 'Bukti gambar tidak dapat disimpan.']);
        }

        $id = (string) Str::uuid();
        $path = 'evidence/'.$id.($mime === 'image/jpeg' ? '.jpg' : '.png');
        if (! Storage::disk('local')->put($path, $contents)) {
            throw new \RuntimeException('Private evidence storage failed.');
        }

        try {
            return DB::transaction(fn () => Attachment::create([
                'id' => $id,
                'owner_user_id' => $teacherUser->id,
                'private_path' => $path,
                'mime_type' => $mime,
                'size' => strlen($contents),
                'checksum' => hash('sha256', $contents),
            ]));
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }
}
