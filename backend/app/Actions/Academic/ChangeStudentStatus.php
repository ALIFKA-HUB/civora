<?php

namespace App\Actions\Academic;

use App\Actions\Accounts\AccountSecurity;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeStudentStatus
{
    public function execute(User $admin, Student $student, string $status, string $reason): Student
    {
        return DB::transaction(function () use ($admin, $student, $status, $reason): Student {
            // Match OpenSemester: multiple semester locks are always acquired in ID order.
            Semester::orderBy('id')->lockForUpdate()->get();
            $student = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            [$admin, $user] = AccountSecurity::lockPair($admin, $student->user);
            AccountSecurity::admin($admin);
            PlacementRules::student($student);
            if (! in_array($status, ['transferred', 'graduated'], true)) {
                throw ValidationException::withMessages(['status' => 'Status tujuan harus pindah atau lulus.']);
            }
            $reason = AccountSecurity::reason($reason);
            $at = CarbonImmutable::now();
            $enrollments = $student->enrollments()->orderBy('id')->lockForUpdate()->get();
            foreach ($enrollments as $enrollment) {
                // Prepared future/equal-start rows cannot form a positive interval ending now.
                if ($enrollment->valid_from->lessThan($at) && (! $enrollment->valid_until || $enrollment->valid_until->greaterThan($at))) {
                    $enrollment->update(['valid_until' => $at]);
                }
            }
            $before = ['education_status' => $student->education_status, 'account_status' => $user->account_status];
            AccountSecurity::revoke($user);
            $user->update(['account_status' => 'inactive', 'remember_token' => null]);
            $user->temporaryCredentials()->delete();
            $student->update(['education_status' => $status]);
            PlacementRules::audit($admin, 'student', $student->id, 'student_status_changed', $before, ['education_status' => $status, 'account_status' => 'inactive'], $reason);

            return $student;
        }, 3);
    }
}
