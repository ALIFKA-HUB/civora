<?php

namespace App\Policies;

use App\Actions\Accounts\AccountSecurity;
use App\Models\Student;
use App\Models\User;

class StudentPolicy
{
    public function view(User $user, Student $student): bool
    {
        return AccountSecurity::active($user) && ($user->role === 'admin'
            || ($user->role === 'student' && $student->user_id === $user->id)
            || ($user->role === 'teacher' && $this->schoolActive($student)));
    }

    public function recordPoints(User $user, Student $student): bool
    {
        return AccountSecurity::active($user) && $user->role === 'teacher' && $this->schoolActive($student);
    }

    public function revealTemporaryCredential(User $user, Student $student): bool
    {
        return AccountSecurity::active($user) && ($user->role === 'admin' || ($user->role === 'teacher' && $this->schoolActive($student)));
    }

    private function schoolActive(Student $student): bool
    {
        return $student->education_status === 'active' && in_array($student->user->account_status, ['active', 'pending_activation'], true);
    }
}
