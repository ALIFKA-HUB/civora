<?php

namespace App\Actions\Points;

use App\Actions\Accounts\AccountSecurity;
use App\Models\FollowUp;
use App\Models\Semester;
use App\Models\Student;
use App\Models\ThresholdAlert;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class RecordFollowUp
{
    public function execute(User $admin, ThresholdAlert $alert, array $attributes): FollowUp
    {
        return DB::transaction(function () use ($admin, $alert, $attributes): FollowUp {
            $identity = ThresholdAlert::whereKey($alert->id)->firstOrFail(['id', 'student_id', 'semester_id']);
            Semester::whereKey($identity->semester_id)->lockForUpdate()->firstOrFail();
            Student::whereKey($identity->student_id)->lockForUpdate()->firstOrFail();
            $alert = ThresholdAlert::whereKey($identity->id)->lockForUpdate()->firstOrFail();
            $admin = User::whereKey($admin->id)->lockForUpdate()->firstOrFail();
            AccountSecurity::admin($admin);
            $unknown = array_diff(array_keys($attributes), ['action', 'scheduled_or_performed_at', 'notes', 'status']);
            if ($unknown !== []) {
                throw ValidationException::withMessages([array_values($unknown)[0] => 'Field tidak diizinkan.']);
            }
            $data = Validator::make($attributes, [
                'action' => ['required', 'string', 'max:1000'],
                'scheduled_or_performed_at' => ['required', 'date'],
                'notes' => ['nullable', 'string', 'max:5000'],
                'status' => ['required', 'in:planned,completed,cancelled'],
            ])->validate();
            $data['action'] = trim($data['action']);
            if ($data['action'] === '') {
                throw ValidationException::withMessages(['action' => 'Tindakan wajib diisi.']);
            }

            return $alert->followUps()->create([...$data, 'performed_by' => $admin->id]);
        }, 3);
    }
}
