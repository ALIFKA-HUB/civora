<?php

namespace App\Actions\Points;

use App\Actions\Accounts\AccountSecurity;
use App\Models\AuditLog;
use App\Models\Semester;
use App\Models\Student;
use App\Models\ThresholdPolicy;
use App\Models\User;
use App\Services\Points\ThresholdEvaluator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SetThresholdPolicy
{
    public function execute(User $admin, Semester $semester, array $levels, string $reason): ThresholdPolicy
    {
        return DB::transaction(function () use ($admin, $semester, $levels, $reason): ThresholdPolicy {
            $semester = Semester::whereKey($semester->id)->lockForUpdate()->firstOrFail();
            $studentIds = Student::query()
                ->whereIn('id', function ($query) use ($semester): void {
                    $query->select('student_id')->from('point_records')->where('semester_id', $semester->id)
                        ->union(DB::table('threshold_alerts')->select('student_id')->where('semester_id', $semester->id));
                })
                ->orderBy('id')->pluck('id');
            $students = Student::whereIn('id', $studentIds)->orderBy('id')->lockForUpdate()->get();
            $admin = User::whereKey($admin->id)->lockForUpdate()->firstOrFail();
            AccountSecurity::admin($admin);
            $reason = AccountSecurity::reason($reason);
            $data = Validator::make(['levels' => $levels], [
                'levels' => ['required', 'array', 'min:1'],
                'levels.*' => ['required', 'array:boundary_points,label,recommended_action'],
                'levels.*.boundary_points' => ['required', 'integer', 'min:1', 'max:2147483647', 'distinct'],
                'levels.*.label' => ['required', 'string', 'max:255'],
                'levels.*.recommended_action' => ['required', 'string', 'max:1000'],
            ])->validate();

            if ($semester->status === 'closed' && strlen($reason) < 5) {
                throw ValidationException::withMessages(['reason' => 'Perubahan kebijakan semester tertutup memerlukan alasan.']);
            }

            $latest = $semester->thresholdPolicies()->orderByDesc('version')->first();
            $before = $latest ? [
                'version' => $latest->version,
                'levels' => $latest->thresholdLevels()->orderBy('boundary_points')->get(['boundary_points', 'label', 'recommended_action'])->toArray(),
            ] : null;
            $policy = $semester->thresholdPolicies()->create([
                'version' => ($latest?->version ?? 0) + 1,
                'created_by' => $admin->id,
                'reason' => $reason,
            ]);
            foreach ($data['levels'] as $level) {
                $policy->thresholdLevels()->create($level);
            }

            $evaluator = app(ThresholdEvaluator::class);
            foreach ($students as $student) {
                $evaluator->evaluate($student, $semester);
            }

            AuditLog::create([
                'actor_user_id' => $admin->id,
                'action' => 'threshold_policy_created',
                'resource_type' => 'threshold_policy',
                'resource_id' => $policy->id,
                'safe_before' => $before,
                'safe_after' => ['version' => $policy->version, 'levels' => $policy->thresholdLevels()->orderBy('boundary_points')->get(['boundary_points', 'label', 'recommended_action'])->toArray()],
                'reason' => $reason,
                'request_id' => request()->attributes->get('request_id') ?? (string) Str::uuid(),
            ]);

            return $policy;
        }, 3);
    }
}
