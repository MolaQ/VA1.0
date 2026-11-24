<?php

namespace App\Livewire\Concerns;

use App\Models\Club;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

trait InteractsWithClub
{
    protected function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    protected function club(): Club
    {
        $club = $this->user()->currentClub;

        abort_if($club === null, 403, __('No club selected.'));

        return $club;
    }

    protected function toast(string $message, string $tone = 'success'): void
    {
        $this->dispatch('toast', message: $message, tone: $tone);
    }
}
