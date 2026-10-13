<?php

namespace App\Livewire\Admin;

use App\Actions\Points\SetThresholdPolicy;
use App\Livewire\Admin\Concerns\RequiresRecentAdminConfirmation;
use App\Models\Semester;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Thresholds extends Component
{
    use RequiresRecentAdminConfirmation;

    public string $semesterId = '';

    public string $reason = '';

    public array $levels = [['boundary_points' => '', 'label' => '', 'recommended_action' => '']];

    public string $message = '';

    public function mount(): void
    {
        $this->semesterId = (string) (Semester::query()->where('status', 'active')->orderBy('start_date')->value('id') ?? '');
    }

    public function addLevel(): void
    {
        $this->levels[] = ['boundary_points' => '', 'label' => '', 'recommended_action' => ''];
    }

    public function removeLevel(int $index): void
    {
        if (count($this->levels) > 1 && array_key_exists($index, $this->levels)) {
            unset($this->levels[$index]);
            $this->levels = array_values($this->levels);
        }
    }

    public function save(): void
    {
        $this->requireRecentAdminConfirmation();
        $data = $this->validate([
            'semesterId' => ['required', 'uuid', 'exists:semesters,id'],
            'reason' => ['required', 'string', 'max:1000'],
            'levels' => ['required', 'array', 'min:1'],
            'levels.*' => ['required', 'array:boundary_points,label,recommended_action'],
            'levels.*.boundary_points' => ['required', 'integer', 'min:1', 'max:2147483647', 'distinct'],
            'levels.*.label' => ['required', 'string', 'max:255'],
            'levels.*.recommended_action' => ['required', 'string', 'max:1000'],
        ]);

        $policy = app(SetThresholdPolicy::class)->execute(
            auth('web')->user(),
            Semester::findOrFail($data['semesterId']),
            $data['levels'],
            $data['reason'],
        );
        $this->message = "Kebijakan versi {$policy->version} disimpan. Peringatan kini mengikuti kebijakan terbaru.";
        $this->reason = '';
    }

    public function render()
    {
        $semesters = Semester::query()->with('academicYear')->orderByDesc('start_date')->get();
        $semester = $this->semesterId !== '' ? Semester::with(['thresholdPolicies.thresholdLevels'])->find($this->semesterId) : null;
        $policies = $semester?->thresholdPolicies->sortByDesc('version')->values() ?? collect();
        $latestPolicy = $policies->first();
        $alerts = $latestPolicy
            ? $semester->thresholdAlerts()
                ->with(['student.user', 'thresholdLevel', 'followUps'])
                ->where('currently_reached', true)
                ->whereHas('thresholdLevel', fn ($query) => $query->where('policy_id', $latestPolicy->id))
                ->orderByDesc('reached_at')->get()
            : collect();

        return view('livewire.admin.thresholds', compact('semesters', 'semester', 'policies', 'latestPolicy', 'alerts'), ['title' => 'Ambang & tindak lanjut']);
    }
}
