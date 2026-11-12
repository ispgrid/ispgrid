<?php

use App\Http\Middleware\SelfcareSession;
use App\Models\SelfcareAccount;
use App\Support\PublicPage;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The connections a mobile login may open: every customer who may use Selfcare and has that mobile number. Choosing
 * one makes it the logged-in connection; the password stays the one the customer logged in with. A connection that
 * is not in the list (another mobile, a tampered id) is not found.
 */
new class extends Component
{
    /**
     * @return list<array{id: int, name: string, address: ?string, username: string, account_number: ?string, current: bool}>
     */
    #[Computed]
    public function connections(): array
    {
        $current = SelfcareSession::account()?->id;

        return SelfcareSession::connections()->load('address')->map(fn (SelfcareAccount $account): array => [
            'id' => $account->id,
            'name' => (string) ($account->fullname ?: $account->username),
            'address' => $account->address === null ? null : implode(', ', array_filter([$account->address->line1, $account->address->area, $account->address->city])),
            'username' => (string) $account->username,
            'account_number' => $account->account_number,
            'current' => $account->id === $current,
        ])->values()->all();
    }

    public function open(int $id): void
    {
        $target = SelfcareSession::connections()->firstWhere('id', $id);
        abort_if($target === null, 404);

        Auth::guard(SelfcareSession::GUARD)->login($target);
        activity()->performedOn($target)->causedBy($target)->event('selfcare-switch')->log('selfcare-switch');

        $this->redirectRoute('selfcare.dashboard');
    }

    public function render(): View
    {
        return $this->view()->layout('layouts::public', [...PublicPage::data(), 'title' => __('Choose a connection')]);
    }
};
