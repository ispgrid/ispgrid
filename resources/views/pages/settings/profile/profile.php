<?php

use App\Concerns\ProfileValidationRules;
use App\Http\Middleware\SetLocale;
use App\Livewire\Concerns\ShowsToasts;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Profile settings')] class extends Component
{
    use ProfileValidationRules, ShowsToasts;

    public string $name = '';

    public string $email = '';

    /**
     * The staff member's own language; empty follows the language in App settings.
     */
    public string $locale = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
        $this->locale = (string) Auth::user()->locale;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $user->fill($this->validate($this->profileRules($user->id)))->save();

        $this->toast(__('Profile updated.'));
    }

    /**
     * Saves the language and reloads the page, so the whole panel (menu included) shows it.
     */
    public function updateLanguage(): void
    {
        $validated = $this->validate([
            'locale' => ['nullable', Rule::in(array_keys(config('app.locales')))],
        ]);

        $user = Auth::user();
        $user->update(['locale' => $validated['locale'] ?: null]);

        App::setLocale(SetLocale::resolve($user->locale));
        session()->flash('toast', __('Language saved.'));
        $this->redirectRoute('profile.edit');
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function languages(): array
    {
        return config('app.locales');
    }

    /**
     * The name of the language in App settings, which an empty choice follows.
     */
    #[Computed]
    public function panelLanguage(): string
    {
        return config('app.locales')[SetLocale::resolve()];
    }
};
