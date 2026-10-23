<?php

namespace App\Actions\Imports;

use App\Actions\Accounts\AccountSecurity;
use App\Models\Classroom;
use App\Models\ImportBatch;
use App\Models\LoginIdentifier;
use App\Models\Major;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Imports\AccountImportReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PreviewAccountsImport
{
    public function execute(User $admin, UploadedFile $file, string $entityType, ?Semester $semester): ImportBatch
    {
        AccountSecurity::admin($admin->fresh());
        if (! in_array($entityType, ['student', 'teacher'], true)) {
            throw ValidationException::withMessages(['entity_type' => 'Jenis import tidak valid.']);
        }
        if ($entityType === 'student' && ! $semester) {
            throw ValidationException::withMessages(['semester' => 'Semester target harus dipilih.']);
        }
        if ($semester) {
            $semester = Semester::with('academicYear')->find($semester->id);
            if (! $semester || ! in_array($semester->status, ['draft', 'active'], true)
                || $semester->start_date->lessThan($semester->academicYear->start_date)
                || $semester->end_date->greaterThan($semester->academicYear->end_date)) {
                throw ValidationException::withMessages(['semester' => 'Semester target harus draft/aktif dan sesuai tahun ajaran.']);
            }
        }

        $parsed = app(AccountImportReader::class)->read($file, $entityType);
        $errors = [...$parsed['errors'], ...$this->validateRows($parsed['rows'], $entityType, $semester)];
        $path = $file->storeAs('imports', Str::uuid().'.'.strtolower($file->getClientOriginalExtension()), 'local');
        if (! $path) {
            throw ValidationException::withMessages(['file' => 'Berkas import tidak dapat disimpan.']);
        }

        return ImportBatch::create([
            'admin_id' => $admin->id,
            'entity_type' => $entityType,
            'file_path' => $path,
            'semester_id' => $semester?->id,
            'status' => 'preview',
            'row_count' => count($parsed['rows']),
            'parsed_rows' => $parsed['rows'],
            'error_summary' => $errors,
        ]);
    }

    /** @param list<array<string, string>> $rows
     *  @return list<array{row: int, field: string, message: string}> */
    public function validateRows(array $rows, string $entityType, ?Semester $semester): array
    {
        $errors = [];
        $seen = [];
        $identifiers = array_values(array_unique(array_filter(array_map(
            fn ($row) => mb_strtolower(trim($row[$entityType === 'student' ? 'nisn' : 'nip'] ?? '')),
            $rows,
        ))));
        if ($entityType === 'student') {
            $existingIdentifiers = array_fill_keys(Student::whereIn('nisn', $identifiers)->pluck('nisn')->map(fn ($value) => mb_strtolower($value))->all(), true);
        } else {
            $existingIdentifiers = array_fill_keys(Teacher::query()->whereIn('nip', $identifiers)->orWhereIn('teacher_code', $identifiers)->get(['nip', 'teacher_code'])
                ->flatMap(fn ($teacher) => array_filter([$teacher->nip, $teacher->teacher_code], fn ($value) => $value !== null && $value !== ''))
                ->map(fn ($value) => mb_strtolower($value))->all(), true);
        }
        $existingAliases = array_fill_keys(LoginIdentifier::where('role', $entityType)->whereIn('normalized_value', $identifiers)
            ->pluck('normalized_value')->all(), true);
        $classrooms = [];
        $majorCodes = [];
        if ($entityType === 'student' && $semester) {
            $classCodes = array_values(array_unique(array_map(fn ($row) => trim($row['class_code'] ?? ''), $rows)));
            $classrooms = Classroom::query()->with('major')->where('academic_year_id', $semester->academic_year_id)
                ->whereIn('code', $classCodes)->get()->keyBy('code')->all();
            $majorCodes = array_fill_keys(Major::pluck('code')->map(fn ($code) => mb_strtolower($code))->all(), true);
        }

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $name = trim($row['name'] ?? '');
            if ($name === '' || mb_strlen($name) > 255) {
                $errors[] = ['row' => $rowNumber, 'field' => 'name', 'message' => 'Nama wajib diisi, maksimal 255 karakter.'];
            }

            if ($entityType === 'student') {
                $nisn = trim($row['nisn'] ?? '');
                $key = mb_strtolower($nisn);
                if ($nisn === '' || ! ctype_digit($nisn) || strlen($nisn) > 30) {
                    $errors[] = ['row' => $rowNumber, 'field' => 'nisn', 'message' => 'NISN wajib berupa teks angka maksimal 30 karakter.'];
                } elseif (isset($seen[$key]) || isset($existingIdentifiers[$key]) || isset($existingAliases[$key])) {
                    $errors[] = ['row' => $rowNumber, 'field' => 'nisn', 'message' => 'NISN sudah digunakan dalam berkas atau database.'];
                }
                $seen[$key] = true;
                $this->validateClass($row, $rowNumber, $semester, $classrooms, $majorCodes, $errors);
            } else {
                $nip = trim($row['nip'] ?? '');
                if ($nip !== '') {
                    $key = mb_strtolower($nip);
                    if (! ctype_digit($nip) || strlen($nip) > 30) {
                        $errors[] = ['row' => $rowNumber, 'field' => 'nip', 'message' => 'NIP harus berupa teks angka maksimal 30 karakter.'];
                    } elseif (isset($seen[$key]) || isset($existingAliases[$key]) || isset($existingIdentifiers[$key])) {
                        $errors[] = ['row' => $rowNumber, 'field' => 'nip', 'message' => 'NIP sudah digunakan dalam berkas atau namespace login guru.'];
                    }
                    $seen[$key] = true;
                }
            }
        }

        return $errors;
    }

    /** @param array<string, string> $row
     *  @param list<array{row: int, field: string, message: string}> $errors */
    private function validateClass(array $row, int $rowNumber, ?Semester $semester, array $classrooms, array $majorCodes, array &$errors): void
    {
        if (! $semester) {
            $errors[] = ['row' => $rowNumber, 'field' => 'semester', 'message' => 'Semester target harus dipilih.'];

            return;
        }
        $majorCode = trim($row['major_code'] ?? '');
        $classCode = trim($row['class_code'] ?? '');
        $classroom = $classrooms[$classCode] ?? null;
        if ($majorCode === '' || $classCode === '' || ! $classroom || ! $classroom->active || ! $classroom->major->active
            || mb_strtolower($classroom->major->code) !== mb_strtolower($majorCode)) {
            $errors[] = ['row' => $rowNumber, 'field' => 'class_code', 'message' => 'Kode kelas/jurusan harus cocok, aktif, dan sesuai tahun ajaran semester.'];
        }
        if ($majorCode !== '' && ! isset($majorCodes[mb_strtolower($majorCode)])) {
            $errors[] = ['row' => $rowNumber, 'field' => 'major_code', 'message' => 'Kode jurusan tidak dikenal.'];
        }
    }
}
