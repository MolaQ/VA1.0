<?php

use App\Enums\Role;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Models\User;
use Livewire\Livewire;

it('registers a new club with its administrator', function () {
    Livewire::test(Register::class)
        ->set('club_name', 'UKS Test')
        ->set('city', 'Kraków')
        ->set('name', 'Jan Admin')
        ->set('email', 'jan@example.com')
        ->set('password', 'password-123')
        ->set('password_confirmation', 'password-123')
        ->call('register')
        ->assertRedirect(route('dashboard'));

    $user = User::where('email', 'jan@example.com')->firstOrFail();

    expect($user->currentClub->name)->toBe('UKS Test')
        ->and($user->hasRole($user->currentClub, Role::Admin))->toBeTrue()
        ->and($user->currentClub->teams()->count())->toBe(1);

    $this->assertAuthenticatedAs($user);
});

it('logs in and remembers the user by default', function () {
    $user = User::factory()->create();

    Livewire::test(Login::class)
        ->assertSet('remember', true)
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->remember_token)->not->toBeNull();
});

it('rejects a wrong password', function () {
    $user = User::factory()->create();

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'nope')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
});
