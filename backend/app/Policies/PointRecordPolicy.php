<?php

namespace App\Policies;

use App\Actions\Accounts\AccountSecurity;
use App\Models\PointRecord;
use App\Models\User;

class PointRecordPolicy
{
    public function view(User $user, PointRecord $record): bool
    {
        return AccountSecurity::active($user) && ($user->role === 'admin'
            || ($user->role === 'student' && $record->student->user_id === $user->id)
            || ($user->role === 'teacher' && ($record->teacher->user_id === $user->id || (new StudentPolicy)->view($user, $record->student))));
    }

    public function requestCorrection(User $user, PointRecord $record): bool
    {
        return AccountSecurity::active($user) && $user->role === 'teacher' && $record->teacher->user_id === $user->id;
    }

    public function requestObjection(User $user, PointRecord $record): bool
    {
        return AccountSecurity::active($user) && $user->role === 'student' && $record->student->user_id === $user->id;
    }

    public function update(User $user, PointRecord $record): bool
    {
        return AccountSecurity::active($user) && $user->role === 'admin';
    }
}
