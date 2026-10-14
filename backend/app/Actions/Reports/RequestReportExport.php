<?php

namespace App\Actions\Reports;

use App\Jobs\GeneratePointReport;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Reports\PointReportQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RequestReportExport
{
    public function __construct(private readonly PointReportQuery $query) {}

    public function execute(User $admin, array $filters, string $format): ReportExport
    {
        $this->assertActiveAdmin($admin);
        if (! in_array($format, ['xlsx', 'pdf'], true)) {
            throw ValidationException::withMessages(['format' => 'Format laporan harus xlsx atau pdf.']);
        }

        $filters = $this->query->validateFilters($filters);
        $export = DB::transaction(fn () => ReportExport::create([
            'admin_id' => $admin->id,
            'filters' => [...$filters, 'format' => $format],
            'status' => 'queued',
        ]));

        GeneratePointReport::dispatch($export->id);

        return $export->refresh();
    }

    private function assertActiveAdmin(User $admin): void
    {
        $fresh = User::query()->find($admin->id);
        if (! $fresh || $fresh->role !== 'admin' || $fresh->account_status !== 'active') {
            throw new AuthorizationException('Admin aktif diperlukan untuk membuat laporan.');
        }
    }
}
