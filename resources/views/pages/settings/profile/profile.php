<?php

use App\Concerns\ProfileValidationRules;
use App\Livewire\Concerns\ShowsToasts;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Profile settings')] class extends Component
{
    use ProfileValidationRules, ShowsToasts;

    public string $name = '';

    public string $email = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
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
};
