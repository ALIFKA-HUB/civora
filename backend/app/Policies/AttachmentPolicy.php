<?php

namespace App\Policies;

use App\Actions\Accounts\AccountSecurity;
use App\Models\Attachment;
use App\Models\User;

class AttachmentPolicy
{
    public function view(User $user, Attachment $attachment): bool
    {
        if (! AccountSecurity::active($user)) {
            return false;
        }
        if ($user->role === 'admin') {
            return true;
        }
        $revisions = $attachment->recordRevisions()->with('pointRecord.student.user', 'pointRecord.teacher')->get();
        if ($revisions->isEmpty()) {
            return $user->role === 'teacher' && $attachment->owner_user_id === $user->id;
        }

        return $revisions->contains(fn ($revision) => (new PointRecordPolicy)->view($user, $revision->pointRecord));
    }
}
