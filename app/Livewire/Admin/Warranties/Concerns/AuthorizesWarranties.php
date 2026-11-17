<?php

namespace App\Livewire\Admin\Warranties\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

trait AuthorizesWarranties
{
    protected function warrantyActor(bool $owner = false): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User && ($owner ? $actor->isAdmin() : $actor->isStaff()), 403);
        abort_unless((bool) config('warranty.enabled', false), 404);

        return $actor;
    }
}
