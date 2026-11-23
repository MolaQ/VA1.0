<?php

namespace App\Livewire;

use App\Livewire\Concerns\InteractsWithClub;
use App\Support\Locales;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The user's own profile and preferences (language, theme, password).
 * Preferences are stored on the account so they follow the user to every device.
 */
#[Title('Profile and settings')]
class Profile extends Component
{
    use InteractsWithClub;

    public string $name = '';

    public string $email = '';

    public string $locale = 'pl';

    public string $theme = 'dark';

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $user = $this->user();
        $this->name = $user->name;
        $this->email = $user->email;
        $this->locale = $user->locale ?? app()->getLocale();
        $this->theme = $user->theme ?? 'dark';
    }

    public function saveProfile(): void
    {
        $user = $this->user();

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'lowercase', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'locale' => ['required', Rule::in(array_keys(Locales::supported()))],
            'theme' => ['required', Rule::in(['dark', 'light', 'system'])],
        ]);

        $localeChanged = $user->locale !== $data['locale'];
        $user->forceFill($data)->save();
        session()->put('locale', $data['locale']);

        if ($localeChanged) {
            app()->setLocale($data['locale']);
            session()->flash('toast', __('Settings saved.'));
            $this->redirectRoute('profile');

            return;
        }

        $this->dispatch('theme-changed', theme: $data['theme']);
        $this->toast(__('Settings saved.'));
    }

    public function updatePassword(): void
    {
        $this->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $this->user()->forceFill(['password' => Hash::make($this->password)])->save();
        $this->reset(['current_password', 'password', 'password_confirmation']);
        $this->toast(__('Password changed.'));
    }

    public function render(): View
    {
        return view('livewire.profile', ['locales' => Locales::supported()]);
    }
}
