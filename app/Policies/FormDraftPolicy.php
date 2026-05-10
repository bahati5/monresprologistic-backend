<?php

namespace App\Policies;

use App\Models\FormDraft;
use App\Models\User;

class FormDraftPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, FormDraft $draft): bool
    {
        return (int) $draft->user_id === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, FormDraft $draft): bool
    {
        return (int) $draft->user_id === (int) $user->id;
    }

    public function delete(User $user, FormDraft $draft): bool
    {
        return (int) $draft->user_id === (int) $user->id;
    }
}
