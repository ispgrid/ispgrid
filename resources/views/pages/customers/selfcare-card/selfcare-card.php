<?php

use App\Actions\Customers\SetSelfcarePassword;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\NotificationLog;
use App\Notifications\Triggers\SelfcareLogin;
use App\Support\Selfcare;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The customer's Selfcare login on the customer page (Details tab): whether a password is set, the last login, and
 * Set selfcare password (random or typed). A new password is shown once in a dialog with the whole login to copy;
 * after closing it cannot be read back (only the hash is kept).
 */
new class extends Component
{
    use AuthorizesEveryRequest, ShowsToasts;

    #[Locked]
    public int $accountId;

    /**
     * The password just set, shown in the dialog until it closes.
     */
    #[Locked]
    public ?string $shownPassword = null;

    public string $typed = '';

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

    /**
     * @return array{set: ?string, last_login: ?string, must_change: bool}
     */
    #[Computed]
    public function status(): array
    {
        $account = $this->account;
        $format = AppSetting::valueOf('date_format', 'd/m/Y').' H:i';

        return [
            'set' => $account->selfcare_password === null ? null : ($account->selfcare_password_set_at?->format($format) ?? ''),
            'last_login' => $account->selfcare_last_login_at?->format($format),
            'must_change' => (bool) $account->selfcare_must_change,
        ];
    }

    #[Computed]
    public function selfcareUrl(): string
    {
        return Selfcare::url();
    }

    /**
     * The login to copy, while the dialog shows a new password.
     */
    #[Computed]
    public function details(): ?string
    {
        return $this->shownPassword === null ? null : Selfcare::details($this->account, $this->shownPassword);
    }

    /**
     * Sets a new password: the typed one, or a random one when none is given.
     */
    public function setPassword(?string $typed = null): void
    {
        $this->authorizeSetting();
        $typed = $typed === null ? null : trim($typed);

        if ($typed !== null && $typed !== '') {
            $this->typed = $typed;
            $this->validate(['typed' => ['required', 'string', 'max:255', Password::defaults()]], [], ['typed' => __('password')]);
        }

        $this->shownPassword = app(SetSelfcarePassword::class)->handle($this->account, $typed === '' ? null : $typed);
        $this->reset('typed');
        unset($this->account, $this->status, $this->details);
        $this->dispatch('modal-open', name: 'selfcare-password');
    }

    /**
     * Sets a new password and sends it by the "Selfcare login" message rules.
     */
    public function send(): void
    {
        $this->authorizeSetting();

        if (! Selfcare::sendLogin($this->account)) {
            $this->toast(__('No Selfcare login message is switched on. Set a password and copy it instead.'));

            return;
        }

        unset($this->account, $this->status, $this->lastMessage);
        $this->toast(__('A new selfcare password is on its way to the customer.'));
    }

    /**
     * The latest "Selfcare login" message to this customer, so staff see when it did not go out.
     *
     * @return ?array{when: string, status: string, color: string, error: ?string}
     */
    #[Computed]
    public function lastMessage(): ?array
    {
        $log = NotificationLog::query()->where('account_id', $this->accountId)
            ->whereHas('rule', fn ($query) => $query->where('trigger', SelfcareLogin::key()))
            ->latest('id')->first();

        return $log === null ? null : [
            'when' => $log->created_at->format(AppSetting::valueOf('date_format', 'd/m/Y').' H:i'),
            'status' => $log->status->label(),
            'color' => $log->status->color(),
            'error' => $log->error,
        ];
    }

    #[Computed]
    public function canSend(): bool
    {
        return Selfcare::canSendLogin($this->account);
    }

    public function closeDialog(): void
    {
        $this->shownPassword = null;
        unset($this->details);
        $this->dispatch('modal-close', name: 'selfcare-password');
    }

    /**
     * Selfcare open, a PPPoE or IPoE customer, and the Set selfcare passwords box.
     */
    private function authorizeSetting(): void
    {
        abort_unless(Selfcare::isOpen() && Selfcare::eligible($this->account), 404);
        $this->authorize('set-selfcare-passwords');
    }
};
