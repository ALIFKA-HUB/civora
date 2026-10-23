<?php

namespace App\Livewire\Admin;

use App\Actions\Reports\RequestReportExport;
use App\Livewire\Admin\Concerns\RequiresRecentAdminConfirmation;
use App\Models\Classroom;
use App\Models\ReportExport;
use App\Models\SchoolSetting;
use App\Models\Semester;
use App\Services\Reports\PointReportQuery;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Reports extends Component
{
    use RequiresRecentAdminConfirmation;

    public string $semesterId = '';

    public string $classroomId = '';

    public string $category = '';

    public string $status = '';

    public string $exportFormat = 'xlsx';

    public array $summary = ['achievement' => 0, 'violation' => 0];

    public array $rows = [];

    public bool $hasReport = false;

    public string $message = '';

    public function mount(): void
    {
        $this->semesterId = (string) (Semester::query()->where('status', 'active')->value('id') ?? '');
    }

    public function updatedSemesterId(): void
    {
        $this->classroomId = '';
        $this->hasReport = false;
    }

    public function applyFilters(): void
    {
        $values = $this->validate([
            'semesterId' => ['required', 'uuid', Rule::exists('semesters', 'id')],
            'classroomId' => ['nullable', 'uuid', Rule::exists('classrooms', 'id')],
            'category' => ['nullable', Rule::in(['achievement', 'violation'])],
            'status' => ['nullable', Rule::in(['active', 'cancelled'])],
        ]);
        $report = app(PointReportQuery::class)->build(array_filter([
            'semester_id' => $values['semesterId'],
            'classroom_id' => $values['classroomId'] ?: null,
            'category' => $values['category'] ?: null,
            'status' => $values['status'] ?: null,
        ], fn ($value) => $value !== null));
        $this->summary = $report['summary'];
        $this->rows = $report['rows'];
        $this->hasReport = true;
    }

    public function requestExport(): void
    {
        $this->requireRecentAdminConfirmation();
        $values = $this->validate([
            'semesterId' => ['required', 'uuid', Rule::exists('semesters', 'id')],
            'classroomId' => ['nullable', 'uuid', Rule::exists('classrooms', 'id')],
            'category' => ['nullable', Rule::in(['achievement', 'violation'])],
            'status' => ['nullable', Rule::in(['active', 'cancelled'])],
            'exportFormat' => ['required', Rule::in(['xlsx', 'pdf'])],
        ]);
        $export = app(RequestReportExport::class)->execute(auth('web')->user(), array_filter([
            'semester_id' => $values['semesterId'],
            'classroom_id' => $values['classroomId'] ?: null,
            'category' => $values['category'] ?: null,
            'status' => $values['status'] ?: null,
        ], fn ($value) => $value !== null), $values['exportFormat']);
        $this->message = 'Ekspor masuk antrean. Status akan diperbarui setelah proses selesai.';
        $this->dispatch('report-export-requested', exportId: $export->id);
    }

    public function render()
    {
        $semesters = Semester::query()->with('academicYear')->orderByDesc('start_date')->get();
        $classrooms = Classroom::query()->with('major')->orderBy('grade')->orderBy('name')->get();
        $exports = ReportExport::query()->where('admin_id', auth('web')->id())->orderByDesc('created_at')->paginate(10);
        $schoolTimezone = SchoolSetting::query()->value('timezone') ?? config('app.school_timezone', 'Asia/Jakarta');

        return view('livewire.admin.reports', compact('semesters', 'classrooms', 'exports', 'schoolTimezone'), ['title' => 'Laporan']);
    }
}
