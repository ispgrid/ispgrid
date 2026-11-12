<?php

use App\Actions\Customers\ResetPppoePassword;
use App\Enums\ServiceType;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Account;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Reset PPPoE password on the customer page (Details tab), for staff who may edit the customer: a new password from
 * the pattern or random, shown once in a dialog with the login to copy, or sent by the "PPPoE password" message
 * rules. The customer is disconnected so it applies. The password is drawn only in the response that sets it and
 * never kept in the component state.
 */
new class extends Component
{
    use AuthorizesEveryRequest, ShowsToasts;

    #[Locked]
    public int $accountId;

    /**
     * The password just set, for this response only (a protected property is not kept between requests).
     */
    protected ?string $newPassword = null;

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    protected function authorizeAccess(): void
    {
        $this->authorize('view', $this->account);
    }

    #[Computed]
    public function account(): Account
    {
        return Account::query()->findOrFail($this->accountId);
    }

    #[Computed]
    public function canSend(): bool
    {
        return app(ResetPppoePassword::class)->canSend($this->account);
    }

    /**
     * The login to copy, while the dialog shows a new password.
     */
    #[Computed]
    public function details(): ?string
    {
        return $this->newPassword === null ? null : implode("\n", [
            __('Username: :username', ['username' => $this->account->username]),
            __('Password: :password', ['password' => $this->newPassword]),
        ]);
    }

    #[Computed]
    public function shownPassword(): ?string
    {
        return $this->newPassword;
    }

    public function resetPassword(): void
    {
        $this->authorizeReset();
        $this->newPassword = app(ResetPppoePassword::class)->handle($this->account);
        unset($this->account, $this->details, $this->shownPassword);
        $this->dispatch('modal-open', name: 'pppoe-password');
    }

    public function resetAndSend(): void
    {
        $this->authorizeReset();

        if (! $this->canSend) {
            $this->toast(__('No PPPoE password message is switched on. Reset the password and copy it instead.'));

            return;
        }

        $result = app(ResetPppoePassword::class)->handleAndSend($this->account);
        unset($this->account);

        if ($result['queued']) {
            $this->toast(__('A new PPPoE password is on its way to the customer.'));

            return;
        }

        // Nothing went out (no valid number): the password is shown, or nobody would know it.
        $this->newPassword = $result['password'];
        unset($this->details, $this->shownPassword);
        $this->dispatch('modal-open', name: 'pppoe-password');
        $this->toast(__('The message could not go out (no valid mobile number). Give the customer this password.'));
    }

    public function closeDialog(): void
    {
        $this->newPassword = null;
        unset($this->details, $this->shownPassword);
        $this->dispatch('modal-close', name: 'pppoe-password');
    }

    /**
     * A PPPoE customer who is not deleted, and staff who may edit them (they may type a new password in the form).
     */
    private function authorizeReset(): void
    {
        abort_unless($this->account->service_type === ServiceType::Ppp, 404);
        $this->authorize('update', $this->account);
    }
};
