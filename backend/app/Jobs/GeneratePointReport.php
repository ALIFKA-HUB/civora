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
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

class GeneratePointReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly string $reportExportId) {}

    public function handle(): void
    {
        $export = DB::transaction(function (): ?ReportExport {
            $export = ReportExport::query()->whereKey($this->reportExportId)->lockForUpdate()->firstOrFail();
            if (in_array($export->status, ['processing', 'completed'], true)) {
                return null;
            }

            $export->update(['status' => 'processing', 'file_path' => null, 'generated_at' => null, 'expires_at' => null]);

            return $export->fresh();
        });
        if (! $export) {
            return;
        }

        $disk = Storage::disk('local');
        $format = $export->filters['format'] ?? 'xlsx';
        if (! in_array($format, ['xlsx', 'pdf'], true)) {
            $export->update(['status' => 'failed', 'file_path' => null]);

            return;
        }

        $extension = $format === 'pdf' ? 'pdf' : 'xlsx';
        $finalPath = 'reports/'.$export->id.'.'.$extension;
        $temporaryPath = 'reports/.'.$export->id.'.tmp.'.$extension;
        $disk->makeDirectory('reports');

        try {
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
            if (! $disk->move($temporaryPath, $finalPath)) {
                throw new \RuntimeException('File laporan tidak dapat dipindahkan ke lokasi privat.');
            }

            $generatedAt = Carbon::parse($report['generated_at']);
            $export->update([
                'status' => 'completed', 'file_path' => $finalPath,
                'generated_at' => $generatedAt, 'expires_at' => $generatedAt->copy()->addDays(7),
            ]);
        } catch (Throwable $exception) {
            $disk->delete([$temporaryPath, $finalPath]);
            $export->update(['status' => 'failed', 'file_path' => null]);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $export = ReportExport::query()->find($this->reportExportId);
        if ($export) {
            Storage::disk('local')->delete($export->file_path ? [$export->file_path] : []);
            $export->update(['status' => 'failed', 'file_path' => null]);
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
