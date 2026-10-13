<?php

namespace App\Actions\Imports;

use App\Actions\Academic\EnrollStudent;
use App\Actions\Accounts\AccountSecurity;
use App\Actions\Accounts\CreateAccount;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\ImportBatch;
use App\Models\Major;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Services\Academic\SchoolCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CommitAccountsImport
{
    public function execute(User $admin, ImportBatch $batch): ImportBatch
    {
        AccountSecurity::admin($admin->fresh());
        $batch = ImportBatch::findOrFail($batch->id);
        if ($batch->admin_id !== $admin->id) {
            throw new AuthorizationException('Akses ditolak.');
        }
        if ($batch->status === 'confirmed') {
            return $batch;
        }
        if ($batch->status !== 'preview') {
            throw ValidationException::withMessages(['batch' => 'Batch tidak dapat dikonfirmasi.']);
        }
        if ($batch->error_summary !== []) {
            throw ValidationException::withMessages(['batch' => 'Perbaiki kesalahan pratinjau sebelum mengonfirmasi.']);
        }
        try {
            return DB::transaction(function () use ($admin, $batch): ImportBatch {
                $batch = ImportBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
                if ($batch->admin_id !== $admin->id) {
                    throw new AuthorizationException('Akses ditolak.');
                }
                if ($batch->status === 'confirmed') {
                    return $batch;
                }
                if ($batch->status !== 'preview') {
                    throw ValidationException::withMessages(['batch' => 'Batch tidak dapat dikonfirmasi.']);
                }
                if ($batch->created_at->addDays(7)->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
                    $batch->update([
                        'status' => 'failed',
                        'error_summary' => [['row' => 0, 'field' => 'batch', 'message' => 'Pratinjau import telah kedaluwarsa; unggah ulang berkas.']],
                    ]);

                    return $batch;
                }
                if ($batch->error_summary !== []) {
                    throw ValidationException::withMessages(['batch' => 'Perbaiki kesalahan pratinjau sebelum mengonfirmasi.']);
                }

                $semesters = Semester::orderBy('id')->lockForUpdate()->get();
                $semester = $batch->semester_id ? $semesters->firstWhere('id', $batch->semester_id) : null;
                $rows = $batch->parsed_rows ?? [];
                $effectiveAt = '';
                if ($batch->entity_type === 'student') {
                    if (! $semester || ! in_array($semester->status, ['draft', 'active'], true)) {
                        return $this->failForSemester($batch, $rows, 'Semester target sudah tidak tersedia; pilih semester draft atau aktif.');
                    }
                    $year = AcademicYear::whereKey($semester->academic_year_id)->lockForUpdate()->first();
                    if (! $year
                        || $semester->start_date->lessThan($year->start_date)
                        || $semester->end_date->greaterThan($year->end_date)) {
                        return $this->failForSemester($batch, $rows, 'Rentang tanggal semester sudah tidak sesuai dengan tahun ajaran.');
                    }

                    $calendar = new SchoolCalendar;
                    $current = CarbonImmutable::now('UTC');
                    $effective = $current->lessThan($calendar->start($semester)) ? $calendar->start($semester) : $current;
                    if (! $calendar->contains($semester, $effective)) {
                        return $this->failForSemester($batch, $rows, 'Semester sudah berakhir pada waktu penempatan awal.');
                    }
                    $effectiveAt = $effective->toIso8601String();

                    $codes = array_unique(array_map(fn ($row) => $row['class_code'] ?? '', $rows));
                    $classes = Classroom::where('academic_year_id', $semester->academic_year_id)->whereIn('code', $codes)
                        ->orderBy('id')->lockForUpdate()->get();
                    Major::whereIn('id', $classes->pluck('major_id'))->orderBy('id')->lockForUpdate()->get();
                }
                $errors = app(PreviewAccountsImport::class)->validateRows($rows, $batch->entity_type, $semester);
                if ($errors !== []) {
                    $batch->update(['status' => 'failed', 'error_summary' => $errors]);

                    return $batch;
                }

                foreach ($rows as $row) {
                    if ($batch->entity_type === 'student') {
                        $user = app(CreateAccount::class)->execute($admin, [
                            'role' => 'student', 'name' => $row['name'], 'nisn' => $row['nisn'],
                        ]);
                        $student = Student::where('user_id', $user->id)->firstOrFail();
                        $classroom = Classroom::with('major')->where('academic_year_id', $semester->academic_year_id)
                            ->where('code', $row['class_code'])->firstOrFail();
                        if (mb_strtolower($classroom->major->code) !== mb_strtolower($row['major_code'])) {
                            throw ValidationException::withMessages(['classroom' => 'Kode kelas/jurusan berubah selama commit.']);
                        }
                        app(EnrollStudent::class)->execute($admin, $student, $semester, $classroom->id, $effectiveAt);
                    } else {
                        $nip = trim($row['nip'] ?? '');
                        app(CreateAccount::class)->execute($admin, [
                            'role' => 'teacher', 'name' => $row['name'], 'nip' => $nip !== '' ? $nip : null,
                            'teacher_code' => $nip === '' ? $this->teacherCode($rows) : 'G'.Str::upper(Str::random(12)),
                        ]);
                    }
                }

                $batch->update(['status' => 'confirmed', 'confirmed_at' => now()]);

                return $batch->fresh();
            }, 3);
        } catch (Throwable $exception) {
            if ($exception instanceof AuthorizationException) {
                throw $exception;
            }
            ImportBatch::whereKey($batch->id)->where('status', 'preview')->update([
                'status' => 'failed',
                'error_summary' => json_encode([['row' => 0, 'field' => 'batch', 'message' => 'Import gagal; tidak ada akun dibuat.']]),
            ]);
            throw ValidationException::withMessages(['batch' => 'Import gagal; tidak ada akun dibuat.']);
        }
    }

    /** @param list<array<string, string>> $rows */
    private function failForSemester(ImportBatch $batch, array $rows, string $message): ImportBatch
    {
        $errors = [];
        foreach ($rows as $index => $_row) {
            $errors[] = ['row' => $index + 2, 'field' => 'semester', 'message' => $message];
        }
        if ($errors === []) {
            $errors[] = ['row' => 1, 'field' => 'semester', 'message' => $message];
        }
        $batch->update(['status' => 'failed', 'error_summary' => $errors]);

        return $batch;
    }

    /** @param list<array<string, string>> $rows */
    private function teacherCode(array $rows): string
    {
        $nips = array_map(fn ($row) => mb_strtolower(trim($row['nip'] ?? '')), $rows);
        do {
            $code = 'G'.Str::upper(Str::random(12));
            $normalized = mb_strtolower($code);
        } while (in_array($normalized, $nips, true)
            || DB::table('login_identifiers')->where('role', 'teacher')->where('normalized_value', $normalized)->exists()
            || DB::table('teachers')->whereRaw('lower(teacher_code) = ?', [$normalized])->orWhereRaw('lower(nip) = ?', [$normalized])->exists());

        return $code;
    }
}
