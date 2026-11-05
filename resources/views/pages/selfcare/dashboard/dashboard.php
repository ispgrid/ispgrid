<?php

use App\Http\Middleware\SelfcareSession;
use App\Models\SelfcareAccount;
use App\Support\PublicPage;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The customer's one page after login. The customer is always the logged-in one, never a request parameter.
 */
new class extends Component
{
    #[Computed]
    public function account(): SelfcareAccount
    {
        return SelfcareSession::account() ?? abort(403);
    }

    public function render(): View
    {
        return $this->view()->layout('layouts::public', [...PublicPage::data(), 'title' => __('Selfcare')]);
    }
};
