<?php

namespace App\Livewire\Club;

use App\Enums\Role;
use App\Events\ClubActivity;
use App\Livewire\Concerns\InteractsWithClub;
use App\Models\Membership;
use App\Models\Player;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Club administrators manage who has access and with which role.
 * Parents are linked to their children (players); player accounts to their profile.
 */
#[Title('Members & roles')]
class Members extends Component
{
    use InteractsWithClub;

    public string $name = '';

    public string $email = '';

    public string $role = 'trainer';

    /** @var array<int, int|string> */
    public array $player_ids = [];

    public ?string $temporaryPassword = null;

    public function mount(): void
    {
        Gate::authorize('manage', $this->club());
    }

    public function invite(): void
    {
        $club = $this->club();
        Gate::authorize('manage', $club);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::enum(Role::class)],
            'player_ids' => ['array'],
            'player_ids.*' => [Rule::exists('players', 'id')->where('club_id', $club->id)],
        ]);

        $this->temporaryPassword = null;

        DB::transaction(function () use ($club, $data) {
            $user = User::query()->where('email', Str::lower($data['email']))->first();

            if ($user === null) {
                $this->temporaryPassword = Str::password(12, symbols: false);
                $user = User::create([
                    'name' => $data['name'],
                    'email' => Str::lower($data['email']),
                    'password' => $this->temporaryPassword,
                ]);
            }

            $role = Role::from($data['role']);
            $club->addMember($user, $role);
            ClubActivity::dispatch($club->id, ClubActivity::MEMBER_ADDED, [
                'user' => $user->name,
                'role' => $role->value,
            ], $this->user()->id, null, 'staff');

            $ids = array_map('intval', $data['player_ids']);

            if ($role === Role::Parent && $ids !== []) {
                $user->children()->syncWithoutDetaching($ids);
            }

            if ($role === Role::Player && $ids !== []) {
                Player::query()->forClub($club)->whereKey($ids[0])->update(['user_id' => $user->id]);
            }
        });

        $this->reset(['name', 'email', 'player_ids']);
        $this->toast(__('Club member added.'));

        if ($this->temporaryPassword === null) {
            $this->dispatch('close-modal', 'invite');
        }
    }

    public function removeRole(int $membershipId): void
    {
        $club = $this->club();
        Gate::authorize('manage', $club);

        $membership = $club->memberships()->findOrFail($membershipId);

        $adminCount = $club->memberships()->where('role', Role::Admin->value)->count();
        if ($membership->role === Role::Admin && $adminCount <= 1) {
            $this->toast(__('A club must have at least one administrator.'), 'danger');

            return;
        }

        $membership->delete();
        $this->toast(__('Role removed.'));
    }

    public function unlinkChild(int $userId, int $playerId): void
    {
        Gate::authorize('manage', $this->club());

        $player = $this->club()->players()->findOrFail($playerId);
        $player->guardians()->detach($userId);
    }

    public function render(): View
    {
        $club = $this->club();

        $members = $club->memberships()->with('user')->get()
            ->groupBy('user_id')
            ->map(function ($memberships) use ($club) {
                /** @var Membership $first */
                $first = $memberships->first();
                $user = $first->user;

                return [
                    'user' => $user,
                    'memberships' => $memberships->sortBy(fn (Membership $m) => array_search($m->role, Role::cases(), true)),
                    'children' => $user->children()->where('players.club_id', $club->id)->get(),
                    'profile' => $user->playerProfiles()->where('club_id', $club->id)->first(),
                ];
            })
            ->sortBy(fn ($m) => $m['user']->name)
            ->values();

        return view('livewire.club.members', [
            'members' => $members,
            'roles' => Role::cases(),
            'players' => $club->players()->orderBy('last_name')->get(),
        ]);
    }
}
