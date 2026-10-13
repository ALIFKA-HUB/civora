<?php

namespace App\Livewire\Admin;

use App\Actions\Points\RecordFollowUp;
use App\Livewire\Admin\Concerns\RequiresRecentAdminConfirmation;
use App\Models\Semester;
use App\Models\ThresholdAlert;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class FollowUps extends Component
{
    use RequiresRecentAdminConfirmation;

    public string $semesterId = '';

    public string $alertId = '';

    public string $action = '';

    public string $scheduledOrPerformedAt = '';

    public string $notes = '';

    public string $status = 'planned';

    public string $message = '';

    public function mount(): void
    {
        $this->semesterId = (string) (Semester::query()->where('status', 'active')->orderBy('start_date')->value('id') ?? '');
        $this->scheduledOrPerformedAt = now()->timezone(config('app.school_timezone', 'Asia/Jakarta'))->format('Y-m-d\TH:i');
    }

    public function updatedSemesterId(): void
    {
        $this->alertId = '';
    }

    public function record(): void
    {
        $this->requireRecentAdminConfirmation();
        $data = $this->validate([
            'semesterId' => ['required', 'uuid', 'exists:semesters,id'],
            'alertId' => ['required', 'uuid', Rule::exists('threshold_alerts', 'id')->where('semester_id', $this->semesterId)],
            'action' => ['required', 'string', 'max:1000'],
            'scheduledOrPerformedAt' => ['required', 'date_format:Y-m-d\\TH:i'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in(['planned', 'completed', 'cancelled'])],
        ]);

        $alert = ThresholdAlert::findOrFail($data['alertId']);
        $scheduledAt = CarbonImmutable::createFromFormat(
            '!Y-m-d\\TH:i',
            $data['scheduledOrPerformedAt'],
            config('app.school_timezone', 'Asia/Jakarta'),
        )->utc();
        app(RecordFollowUp::class)->execute(auth('web')->user(), $alert, [
            'action' => $data['action'],
            'scheduled_or_performed_at' => $scheduledAt->format('Y-m-d\\TH:i:sP'),
            'notes' => $data['notes'] ?: null,
            'status' => $data['status'],
        ]);
        $this->reset('alertId', 'action', 'notes');
        $this->status = 'planned';
        $this->message = 'Tindak lanjut dicatat. Tindak lanjut tidak mengurangi total poin.';
    }

    public function render()
    {
        $semesters = Semester::query()->with('academicYear')->orderByDesc('start_date')->get();
        $semester = $this->semesterId !== '' ? Semester::with('thresholdPolicies')->find($this->semesterId) : null;
        $latestPolicy = $semester?->thresholdPolicies->sortByDesc('version')->first();
        $alerts = $latestPolicy
            ? $semester->thresholdAlerts()
                ->with(['student.user', 'thresholdLevel', 'followUps.performedBy'])
                ->where('currently_reached', true)
                ->whereHas('thresholdLevel', fn ($query) => $query->where('policy_id', $latestPolicy->id))
                ->orderByDesc('reached_at')->get()
            : collect();

        return view('livewire.admin.follow-ups', compact('semesters', 'semester', 'latestPolicy', 'alerts'), ['title' => 'Tindak lanjut']);
    }
}
