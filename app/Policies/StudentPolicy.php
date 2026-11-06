<?php

namespace App\Policies;

use App\Models\User;

class StudentPolicy
{
    /**
     * Guardianships are maintained by the school the student belongs to.
     */
    public function manageGuardians(User $user, User $student): bool
    {
        if (! $student->hasRole('student')) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return $user->hasRole('school_admin')
            && $user->school_id !== null
            && (int) $user->school_id === (int) $student->school_id;
    }

    /**
     * Only parents with at least one linked student see the children list.
     */
    public function viewChildren(User $user): bool
    {
        return $user->hasRole('parent');
    }
}
