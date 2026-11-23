<?php

namespace App\Livewire\Auth;

use App\Actions\CreateClub;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Self-service sign-up of a new club: creates the club (tenant) and its administrator.
 */
#[Layout('layouts::guest')]
#[Title('Create a club')]
class Register extends Component
{
    public string $club_name = '';

    public string $city = '';

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'club_name' => ['required', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    public function register(CreateClub $createClub): void
    {
        $data = $this->validate();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'locale' => app()->getLocale(),
        ]);

        $createClub->handle($user, $data['club_name'], $data['city'] ?: null);

        Auth::login($user, remember: true);
        session()->regenerate();

        $this->redirectRoute('dashboard', navigate: true);
    }
}
