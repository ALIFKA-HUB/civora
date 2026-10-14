<?php

namespace App\Livewire\Admin;

use App\Models\ReviewRequest;
use App\Models\SchoolSetting;
use App\Models\Semester;
use App\Models\ThresholdAlert;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Dashboard extends Component
{
    public function render()
    {
        $semester = Semester::query()->where('status', 'active')->with('academicYear')->first();

        return view('livewire.admin.dashboard', [
            'title' => 'Dashboard',
            'schoolName' => SchoolSetting::query()->value('name') ?? 'Civora',
            'semester' => $semester,
            'pendingReviews' => ReviewRequest::query()->where('status', 'pending')->count(),
            'activeThresholds' => $semester
                ? ThresholdAlert::query()->where('semester_id', $semester->id)->where('currently_reached', true)->count()
                : 0,
        ]);
    }
}
