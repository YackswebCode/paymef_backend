<?php

namespace App\Policies;

use App\Models\School;
use App\Models\User;

class SchoolPolicy
{
    /**
     * Only administrators and the school's own admins may inspect its wallet.
     */
    public function view(User $user, School $school): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->hasRole('school_admin')
            && $user->school_id !== null
            && (int) $user->school_id === (int) $school->id;
    }
}
