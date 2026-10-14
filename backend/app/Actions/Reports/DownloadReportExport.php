<?php

namespace App\Actions\Reports;

use App\Models\ReportExport;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadReportExport
{
    public function execute(User $admin, string $exportId): StreamedResponse
    {
        $fresh = User::query()->find($admin->id);
        $export = ReportExport::query()->findOrFail($exportId);
        if (! $fresh || $fresh->role !== 'admin' || $fresh->account_status !== 'active'
            || $export->admin_id !== $fresh->id || $export->status !== 'completed'
            || ! $export->expires_at || $export->expires_at->isPast()
            || ! $export->file_path || ! Storage::disk('local')->exists($export->file_path)) {
            throw new AuthorizationException('Laporan tidak tersedia untuk akun ini.');
        }

        $format = $export->filters['format'] ?? 'xlsx';
        $isPdf = $format === 'pdf';
        $filename = 'laporan-poin-'.$export->id.'.'.($isPdf ? 'pdf' : 'xlsx');

        return Storage::disk('local')->download($export->file_path, $filename, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Type' => $isPdf ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
