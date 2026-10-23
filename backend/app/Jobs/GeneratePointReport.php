<?php

namespace App\Jobs;

use App\Models\ReportExport;
use App\Services\Reports\PointReportQuery;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

class GeneratePointReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout;

    public ?string $claimToken = null;

    public function __construct(public readonly string $reportExportId)
    {
        $this->timeout = max(1, min(60, $this->retryAfterSeconds() - 10));
    }

    public function leaseSeconds(): int
    {
        return max(1, min($this->timeout + 5, $this->retryAfterSeconds() - 1));
    }

    public function handle(): void
    {
        $claim = DB::transaction(function (): ?array {
            $export = ReportExport::query()->whereKey($this->reportExportId)->lockForUpdate()->firstOrFail();
            if ($export->status === 'completed'
                || ($export->status === 'processing' && $export->generation_lease_until?->isFuture())) {
                return null;
            }

            $stalePath = $export->status === 'processing' ? $export->file_path : null;
            $token = (string) Str::uuid();
            $format = $export->filters['format'] ?? 'xlsx';
            $extension = $format === 'pdf' ? 'pdf' : 'xlsx';
            $temporaryPath = 'reports/'.$export->id.'/.'.$token.'.tmp.'.$extension;
            $finalPath = 'reports/'.$export->id.'/'.$token.'.'.$extension;
            $export->update([
                'status' => 'processing', 'file_path' => $temporaryPath,
                'generated_at' => null, 'expires_at' => null,
                'generation_token' => $token, 'generation_lease_until' => now()->addSeconds($this->leaseSeconds()),
            ]);

            return [$export->fresh(), $token, $temporaryPath, $finalPath, $stalePath];
        });
        if (! $claim) {
            return;
        }

        [$export, $token, $temporaryPath, $finalPath, $stalePath] = $claim;
        $this->claimToken = $token;
        $disk = null;

        try {
            $format = $export->filters['format'] ?? 'xlsx';
            if (! in_array($format, ['xlsx', 'pdf'], true)) {
                throw new \InvalidArgumentException('Format laporan harus xlsx atau pdf.');
            }
            $disk = Storage::disk('local');
            $this->deletePaths($disk, [$stalePath]);
            if (! $disk->makeDirectory(dirname($temporaryPath))) {
                throw new \RuntimeException('Direktori privat laporan tidak dapat disiapkan.');
            }

            $report = app(PointReportQuery::class)->build(collect($export->filters)->except('format')->all());
            $setting = DB::table('school_settings')->first();
            $timezone = $setting?->timezone ?: config('app.school_timezone', 'Asia/Jakarta');
            $semester = DB::table('semesters')->where('id', $export->filters['semester_id'])->first();
            $metadata = [
                'school' => $setting?->name ?: config('app.name'),
                'timezone' => $timezone,
                'semester' => $semester?->name ?? '',
                'semester_period' => $semester ? $semester->start_date.' – '.$semester->end_date : '',
                'filters' => $this->filterDescription(collect($export->filters)->except('format')->all()),
                'generated_at' => Carbon::parse($report['generated_at'])->setTimezone($timezone)->format('Y-m-d H:i:s T'),
            ];

            if ($format === 'xlsx') {
                $this->writeXlsx($report, $metadata, $disk->path($temporaryPath));
            } else {
                $this->writePdf($report, $metadata, $disk->path($temporaryPath));
            }
            if (! $disk->exists($temporaryPath)) {
                throw new \RuntimeException('File sementara laporan tidak tersedia setelah dibuat.');
            }

            $generatedAt = Carbon::parse($report['generated_at']);
            $published = DB::transaction(function () use ($disk, $export, $token, $temporaryPath, $finalPath, $generatedAt): bool {
                $locked = ReportExport::query()->whereKey($export->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'processing' || $locked->generation_token !== $token
                    || ! $locked->generation_lease_until?->isFuture()) {
                    return false;
                }
                if (! $disk->move($temporaryPath, $finalPath)) {
                    throw new \RuntimeException('File laporan tidak dapat dipindahkan ke lokasi privat.');
                }

                $locked->update([
                    'status' => 'completed', 'file_path' => $finalPath,
                    'generated_at' => $generatedAt, 'expires_at' => $generatedAt->copy()->addDays(7),
                    'generation_token' => null, 'generation_lease_until' => null,
                ]);

                return true;
            });
            if (! $published) {
                throw new \RuntimeException('Klaim pembuatan laporan sudah tidak berlaku.');
            }
        } catch (Throwable $exception) {
            if ($disk) {
                $this->deletePaths($disk, [$temporaryPath, $finalPath]);
            }
            $this->failClaim($token, $disk);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->claimToken) {
            $this->failClaim($this->claimToken);

            return;
        }

        $path = DB::transaction(function (): ?string {
            $export = ReportExport::query()->whereKey($this->reportExportId)->lockForUpdate()->first();
            if (! $export || $export->status !== 'processing' || $export->generation_lease_until?->isFuture()) {
                return null;
            }

            $path = $export->file_path;
            $this->markFailed($export);

            return $path;
        });
        try {
            $this->deletePaths(Storage::disk('local'), [$path]);
        } catch (Throwable) {
            // The state transition is authoritative even if the private disk is unavailable.
        }
    }

    private function retryAfterSeconds(): int
    {
        $connection = config('queue.default', 'database');

        return max(1, (int) config("queue.connections.$connection.retry_after", 90));
    }

    private function failClaim(string $token, $disk = null): void
    {
        $path = DB::transaction(function () use ($token): ?string {
            $export = ReportExport::query()->whereKey($this->reportExportId)->lockForUpdate()->first();
            if (! $export || $export->status !== 'processing' || $export->generation_token !== $token) {
                return null;
            }

            $path = $export->file_path;
            $this->markFailed($export);

            return $path;
        });
        if ($disk) {
            $this->deletePaths($disk, [$path]);
        } else {
            try {
                $this->deletePaths(Storage::disk('local'), [$path]);
            } catch (Throwable) {
                // The state transition is authoritative even if the private disk is unavailable.
            }
        }
    }

    private function markFailed(ReportExport $export): void
    {
        $export->update([
            'status' => 'failed', 'file_path' => null,
            'generation_token' => null, 'generation_lease_until' => null,
        ]);
    }

    private function deletePaths($disk, array $paths): void
    {
        $paths = array_values(array_filter($paths));
        if ($paths === []) {
            return;
        }
        try {
            $disk->delete($paths);
        } catch (Throwable) {
            // A failed cleanup cannot change report ownership or expose a partial file.
        }
    }

    private function writeXlsx(array $report, array $metadata, string $path): void
    {
        $book = new Spreadsheet;
        $summary = $book->getActiveSheet();
        $summary->setTitle('Summary');
        $details = $book->createSheet();
        $details->setTitle('Details');

        $info = [
            ['Sekolah', $metadata['school']], ['Semester', $metadata['semester']],
            ['Periode', $metadata['semester_period']], ['Timezone', $metadata['timezone']],
            ['Dibuat pada', $metadata['generated_at']], ['Filter', $metadata['filters']],
        ];
        foreach ($info as $index => [$label, $value]) {
            $row = $index + 1;
            $summary->setCellValue("A$row", $label);
            $summary->setCellValueExplicit("B$row", (string) $value, DataType::TYPE_STRING);
        }
        $summary->setCellValue('A8', 'Kategori');
        $summary->setCellValue('B8', 'Total poin');
        $summary->setCellValueExplicit('A9', 'Prestasi', DataType::TYPE_STRING);
        $summary->setCellValue('B9', $report['summary']['achievement']);
        $summary->setCellValueExplicit('A10', 'Pelanggaran', DataType::TYPE_STRING);
        $summary->setCellValue('B10', $report['summary']['violation']);
        $summary->freezePane('A9');

        $headers = ['ID catatan', 'NISN', 'Nama siswa', 'Semester', 'Waktu', 'Kelas saat kejadian', 'Jurusan saat kejadian', 'Kategori', 'Jenis poin', 'Poin', 'Status', 'Keterangan'];
        foreach ($headers as $column => $header) {
            $details->setCellValueExplicit($details->getCell([$column + 1, 1])->getCoordinate(), $header, DataType::TYPE_STRING);
        }
        foreach ($report['rows'] as $index => $row) {
            $line = $index + 2;
            $values = [$row['id'], $row['nisn'], $row['student'], $row['semester'], $row['occurred_at'], $row['class'], $row['major'], $row['category'], $row['point_name'], null, $row['status'], $row['description']];
            foreach ($values as $column => $value) {
                $coordinate = $details->getCell([$column + 1, $line])->getCoordinate();
                if ($column === 9) {
                    $details->setCellValue($coordinate, $row['points']);
                } else {
                    $details->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
                }
            }
        }
        $details->freezePane('A2');
        $details->setAutoFilter('A1:L'.max(1, count($report['rows']) + 1));

        $writer = new Xlsx($book);
        $writer->save($path);
        $book->disconnectWorksheets();
    }

    private function writePdf(array $report, array $metadata, string $path): void
    {
        $options = new Options;
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setChroot(storage_path('app/private'));
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('reports.points', ['report' => $report, 'metadata' => $metadata])->render(), 'UTF-8');
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();
        $canvas = $pdf->getCanvas();
        $canvas->page_text(760, 570, '{PAGE_NUM} / {PAGE_COUNT}', null, 8, [0, 0, 0]);
        if (file_put_contents($path, $pdf->output(), LOCK_EX) === false) {
            throw new \RuntimeException('PDF laporan tidak dapat ditulis.');
        }
    }

    private function filterDescription(array $filters): string
    {
        $labels = [];
        foreach ([
            'semester_id' => ['semesters', 'Semester', 'name'],
            'major_id' => ['majors', 'Jurusan', 'name'],
            'classroom_id' => ['classrooms', 'Kelas', 'name'],
        ] as $key => [$table, $label, $column]) {
            if (! empty($filters[$key])) {
                $labels[] = $label.': '.(DB::table($table)->where('id', $filters[$key])->value($column) ?: $filters[$key]);
            }
        }
        if (! empty($filters['student_id'])) {
            $student = DB::table('students')->join('users', 'users.id', '=', 'students.user_id')->where('students.id', $filters['student_id'])->first(['students.nisn', 'users.name']);
            $labels[] = 'Siswa: '.($student ? $student->name.' ('.$student->nisn.')' : $filters['student_id']);
        }
        if (! empty($filters['category'])) {
            $labels[] = 'Kategori: '.($filters['category'] === 'achievement' ? 'Prestasi' : 'Pelanggaran');
        }
        if (! empty($filters['from_date']) || ! empty($filters['to_date'])) {
            $labels[] = 'Tanggal: '.($filters['from_date'] ?? 'awal').' – '.($filters['to_date'] ?? 'akhir');
        }
        if (! empty($filters['status'])) {
            $labels[] = 'Status: '.($filters['status'] === 'active' ? 'Berlaku' : 'Dibatalkan');
        }

        return $labels === [] ? 'Semua catatan pada semester' : implode('; ', $labels);
    }
}
