<?php

use App\Actions\Customers\BookAdvanceRenewal;
use App\Actions\Customers\CancelAdvanceRenewal;
use App\Actions\Customers\ChangeExpiry;
use App\Actions\Customers\RechargeAccount;
use App\Actions\Customers\SetOwnSpeed;
use App\Actions\Customers\TerminateAccount;
use App\Enums\AccountStatus;
use App\Enums\RechargeKind;
use App\Exceptions\ExpiryChangedMeanwhile;
use App\Exceptions\RenewalAlreadyWaiting;
use App\Jobs\DisconnectAccount;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Plan;
use App\Models\Radacct;
use App\Models\Recharge;
use App\Support\ExpiryCalculator;
use App\Support\Money;
use App\Support\PlanChoices;
use App\Support\Units;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The customer's plan, its dates and every plan action (Details tab): change plan, renew in advance, change
 * expiry, own speed and terminate. Recharge stays the page's dialog; its button here asks the page to open it.
 * Each action re-checks its permission; the page re-renders after one (account-changed).
 */
new class extends Component
{
    use AuthorizesEveryRequest, ShowsToasts;

    #[Locked]
    public int $accountId;

    /**
     * Change plan: plan, expiry (datetime-local, Y-m-d\TH:i), amount and comment.
     */
    public string $change_plan_id = '';

    public string $change_expiry = '';

    public string $change_amount = '';

    public string $change_note = '';

    /**
     * The expiry the change was computed from (RechargeAccount::expiryKey()).
     */
    #[Locked]
    public string $change_base_expiry = '';

    /**
     * Renew in advance: next plan, expiry, amount and comment. It starts at the current expiry.
     */
    public string $advance_plan_id = '';

    public string $advance_expiry = '';

    public string $advance_amount = '';

    public string $advance_note = '';

    /**
     * Change expiry.
     */
    public string $new_expiry = '';

    public string $expiry_note = '';

    /**
     * Own speed: upload, download, optional end and comment.
     */
    public string $speed_upload = '';

    public string $speed_download = '';

    public string $speed_until = '';

    public string $speed_note = '';

    public string $terminate_note = '';

    public function mount(int $accountId): void
    {
        $this->accountId = $accountId;
        $this->authorizeAccess();
    }

    protected function authorizeAccess(): void
    {
        $this->authorize('view', $this->account);
    }

    #[Computed]
    public function account(): Account
    {
        return Account::withTrashed()->with(['plan' => fn ($query) => $query->withTrashed(), 'waitingRenewal.plan'])->findOrFail($this->accountId);
    }

    /**
     * Re-read the customer after a change on the page or in this card.
     */
    #[On('account-changed')]
    public function refreshAccount(): void
    {
        unset($this->account, $this->details, $this->buttons);
    }

    /**
     * What the card shows.
     *
     * @return array{plan: ?string, price: ?string, started: ?string, expires: ?string, expires_in: ?string, expired: bool, auto_renew: bool, renews: ?string, waiting: ?array{plan: string, starts: string, expiry: string, amount: string}, speed: string, own_speed: ?string, data: ?string, online: bool}
     */
    #[Computed]
    public function details(): array
    {
        $account = $this->account;
        $plan = $account->plan;
        $format = $this->dateFormat();
        $money = Money::fromSettings();
        $waiting = $account->waitingRenewal;
        $expiry = $account->expiry;

        return [
            'plan' => $plan?->name,
            'price' => $plan === null ? null : $money->format((string) $plan->price),
            'started' => $this->periodStart()?->format($format),
            'expires' => $expiry?->format($format),
            'expires_in' => $expiry === null ? null : self::relativeDays($expiry),
            'expired' => $expiry !== null && $expiry->lessThanOrEqualTo(now()),
            'auto_renew' => $account->auto_renew,
            'renews' => match (true) {
                ! $account->auto_renew || $plan === null || $expiry === null => null,
                $waiting !== null => __('The advance renewal starts instead'),
                default => __('Renews :plan on :date', ['plan' => $plan->name, 'date' => $expiry->format($format)]),
            },
            'waiting' => $waiting === null ? null : [
                'plan' => (string) $waiting->plan?->name,
                'starts' => $waiting->starts_at->format($format),
                'expiry' => $waiting->expiry->format($format),
                'amount' => $money->format((string) $waiting->amount),
            ],
            'speed' => $plan === null ? __('None') : "{$plan->upload}/{$plan->download}",
            'own_speed' => ! $account->override_plan ? null : ($account->override_until === null
                ? __('Own speed :speed', ['speed' => "{$account->override_upload}/{$account->override_download}"])
                : __('Own speed :speed until :date', ['speed' => "{$account->override_upload}/{$account->override_download}", 'date' => $account->override_until->format($format)])),
            'data' => $plan?->monthly_data_gb === null ? null : __(':used of :limit this month', [
                'used' => Units::bytes(Radacct::usage($account->username, now()->startOfMonth())['bytes']),
                'limit' => Units::bytes((int) $plan->monthly_data_gb * 1_073_741_824),
            ]),
            'online' => Radacct::query()->where('username', $account->username)->whereNull('acctstoptime')->exists(),
        ];
    }

    /**
     * The buttons this staff member sees. None for a deleted customer.
     *
     * @return array{recharge: bool, change_plan: bool, advance: bool, change_expiry: bool, own_speed: bool, terminate: bool}
     */
    #[Computed]
    public function buttons(): array
    {
        $account = $this->account;
        $user = auth()->user();
        $live = ! $account->trashed();
        $recharge = $live && $user->can('recharge', $account);
        $plan = $account->plan;

        return [
            'recharge' => $recharge,
            'change_plan' => $recharge,
            'advance' => $recharge && $plan !== null && ! $plan->trashed() && ! $plan->hotspot_starts_at_first_login
                && $account->expiry !== null && $account->expiry->greaterThan(now()),
            'change_expiry' => $recharge,
            'own_speed' => $live && $user->can('update', $account),
            'terminate' => $live && $account->status_id !== AccountStatus::Terminated && $user->can('changeStatus', $account),
        ];
    }

    /**
     * Plans for Change plan and Renew in advance (never a first-login plan: both start from a date).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function plans(): array
    {
        return PlanChoices::for($this->account, firstLogin: false);
    }

    public function openChangePlan(): void
    {
        $this->authorizeLive('recharge');
        $this->resetValidation();
        $plan = $this->account->plan;
        $this->change_plan_id = $plan === null || $plan->trashed() || $plan->hotspot_starts_at_first_login ? '' : (string) $plan->id;
        $this->change_note = '';
        $this->fillChangeDefaults();
        $this->dispatch('modal-open', name: 'change-plan');
    }

    public function updatedChangePlanId(): void
    {
        $this->fillChangeDefaults();
    }

    /**
     * Gives the customer the new plan from now (the time left on the old one is dropped), records it with the
     * amount, and disconnects an online customer so the new plan applies at once.
     */
    public function changePlan(RechargeAccount $recharge): void
    {
        $this->authorizeLive('recharge');
        $this->change_note = trim($this->change_note);

        $this->validate([
            'change_plan_id' => ['required', $this->planRule()],
            'change_expiry' => ['required', 'date_format:Y-m-d\TH:i', 'after:now'],
            'change_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'change_note' => ['nullable', 'string', 'max:255'],
        ], ['change_expiry.after' => __('The expiry must be in the future.')], [
            'change_plan_id' => __('plan'), 'change_expiry' => __('expiry'), 'change_amount' => __('amount'), 'change_note' => __('comment'),
        ]);

        $wasInactive = $this->account->status_id === AccountStatus::Inactive;

        try {
            $done = $recharge->handle(
                $this->accountId, (int) $this->change_plan_id, Carbon::createFromFormat('!Y-m-d\TH:i', $this->change_expiry),
                $this->change_amount, $this->nullIfEmpty($this->change_note), $this->change_base_expiry, RechargeKind::ChangePlan,
            );
        } catch (ExpiryChangedMeanwhile) {
            unset($this->account);
            $this->fillChangeDefaults();
            $this->addError('change_expiry', __('The expiry changed meanwhile (another recharge or an edit). The new expiry is filled in from it: check it and save again.'));

            return;
        } catch (ModelNotFoundException) {
            $this->toastDeletedMeanwhile();

            return;
        }

        $message = __('Plan changed until :date.', ['date' => $done->new_expiry->format($this->dateFormat())]);
        $this->finish('change-plan', $message, $this->disconnect(), $wasInactive);
    }

    public function openAdvance(): void
    {
        $this->authorizeLive('recharge');
        $this->resetValidation();
        $plan = $this->account->plan;
        $this->advance_plan_id = $plan === null || $plan->trashed() || $plan->hotspot_starts_at_first_login ? '' : (string) $plan->id;
        $this->advance_note = '';
        $this->fillAdvanceDefaults();
        $this->dispatch('modal-open', name: 'renew-advance');
    }

    public function updatedAdvancePlanId(): void
    {
        $this->fillAdvanceDefaults();
    }

    /**
     * Books a renewal that starts when the current period ends. Nothing changes for the customer until then.
     */
    public function bookRenewal(BookAdvanceRenewal $book): void
    {
        $this->authorizeLive('recharge');
        $this->advance_note = trim($this->advance_note);
        $starts = $this->account->expiry;

        $this->validate([
            'advance_plan_id' => ['required', $this->planRule()],
            'advance_expiry' => ['required', 'date_format:Y-m-d\TH:i', ...($starts === null ? [] : ['after:'.$starts->format('Y-m-d H:i:s')])],
            'advance_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'advance_note' => ['nullable', 'string', 'max:255'],
        ], ['advance_expiry.after' => __('The expiry must be after the start.')], [
            'advance_plan_id' => __('plan'), 'advance_expiry' => __('expiry'), 'advance_amount' => __('amount'), 'advance_note' => __('comment'),
        ]);

        try {
            $renewal = $book->handle($this->accountId, (int) $this->advance_plan_id, Carbon::createFromFormat('!Y-m-d\TH:i', $this->advance_expiry),
                $this->advance_amount, $this->nullIfEmpty($this->advance_note));
        } catch (RenewalAlreadyWaiting) {
            $this->addError('advance_plan_id', __('An advance renewal is already waiting. Cancel it first.'));

            return;
        } catch (InvalidArgumentException) {
            $this->addError('advance_plan_id', __('Renew in advance needs a plan and an expiry in the future.'));

            return;
        } catch (ModelNotFoundException) {
            $this->toastDeletedMeanwhile();

            return;
        }

        $this->finish('renew-advance', __('Advance renewal booked. It starts on :date.', ['date' => $renewal->starts_at->format($this->dateFormat())]));
    }

    public function cancelRenewal(CancelAdvanceRenewal $cancel): void
    {
        $this->authorizeLive('recharge');
        $cancel->handle($this->accountId);
        $this->finish(null, __('Advance renewal cancelled.'));
    }

    public function openChangeExpiry(): void
    {
        $this->authorizeLive('recharge');
        $this->resetValidation();
        $this->new_expiry = $this->account->expiry?->format('Y-m-d\TH:i') ?? '';
        $this->expiry_note = '';
        $this->dispatch('modal-open', name: 'change-expiry');
    }

    /**
     * Sets the expiry without recording money. An online customer is disconnected only when the new expiry is
     * less than a minute away; a later one applies at the next login.
     */
    public function changeExpiry(ChangeExpiry $change): void
    {
        $this->authorizeLive('recharge');
        $this->expiry_note = trim($this->expiry_note);

        $this->validate([
            'new_expiry' => ['required', 'date_format:Y-m-d\TH:i'],
            'expiry_note' => ['nullable', 'string', 'max:255'],
        ], [], ['new_expiry' => __('expiry'), 'expiry_note' => __('comment')]);

        $expiry = Carbon::createFromFormat('!Y-m-d\TH:i', $this->new_expiry);

        try {
            $applyNow = $change->handle($this->accountId, $expiry, $this->nullIfEmpty($this->expiry_note));
        } catch (ModelNotFoundException) {
            $this->toastDeletedMeanwhile();

            return;
        }

        $this->finish('change-expiry', __('Expiry changed to :date.', ['date' => $expiry->format($this->dateFormat())]), $applyNow && $this->disconnect());
    }

    public function openOwnSpeed(): void
    {
        $this->authorizeLive('update');
        $this->resetValidation();
        $account = $this->account;
        $this->speed_upload = $account->override_plan ? (string) $account->override_upload : '';
        $this->speed_download = $account->override_plan ? (string) $account->override_download : '';
        $this->speed_until = $account->override_until?->format('Y-m-d\TH:i') ?? '';
        $this->speed_note = '';
        $this->dispatch('modal-open', name: 'own-speed');
    }

    /**
     * Saves the own speed (the other custom speed fields stay as the customer form set them) and disconnects an
     * online customer so it applies now.
     */
    public function saveOwnSpeed(SetOwnSpeed $set): void
    {
        $this->authorizeLive('update');
        $this->speed_note = trim($this->speed_note);

        $this->withValidator(function ($validator): void {
            $validator->after(function ($validator): void {
                foreach (SetOwnSpeed::errors($this->account, $this->speed_upload, $this->speed_download) as $field => $message) {
                    $validator->errors()->add($field === 'download' ? 'speed_download' : 'speed_upload', $message);
                }
            });
        })->validate([
            'speed_upload' => ['required', 'string', 'max:20'],
            'speed_download' => ['required', 'string', 'max:20'],
            'speed_until' => ['nullable', 'date_format:Y-m-d\TH:i', 'after:now'],
            'speed_note' => ['nullable', 'string', 'max:255'],
        ], ['speed_until.after' => __('The end must be in the future.')], [
            'speed_upload' => __('upload'), 'speed_download' => __('download'), 'speed_until' => __('until'), 'speed_note' => __('comment'),
        ]);

        try {
            $set->handle($this->accountId, $this->speed_upload, $this->speed_download,
                $this->speed_until === '' ? null : Carbon::createFromFormat('!Y-m-d\TH:i', $this->speed_until), $this->nullIfEmpty($this->speed_note));
        } catch (ModelNotFoundException) {
            $this->toastDeletedMeanwhile();

            return;
        }

        $this->finish('own-speed', __('Own speed saved.'), $this->disconnect());
    }

    public function removeOwnSpeed(SetOwnSpeed $set): void
    {
        $this->authorizeLive('update');

        try {
            $set->handle($this->accountId, null, null, null, $this->nullIfEmpty(trim($this->speed_note)));
        } catch (ModelNotFoundException) {
            $this->toastDeletedMeanwhile();

            return;
        }

        $this->finish('own-speed', __('Own speed removed. The plan speed applies.'), $this->disconnect());
    }

    public function openTerminate(): void
    {
        $this->authorizeLive('changeStatus');
        $this->resetValidation();
        $this->terminate_note = '';
        $this->dispatch('modal-open', name: 'terminate');
    }

    /**
     * Removes the plan and sets the customer Terminated; auto renew and a waiting renewal end. Disconnects them.
     */
    public function terminate(TerminateAccount $terminate): void
    {
        $this->authorizeLive('changeStatus');
        $this->terminate_note = trim($this->terminate_note);

        $this->validate(['terminate_note' => ['required', 'string', 'max:500']], [
            'terminate_note.required' => __('Say why the customer is terminated.'),
        ]);

        try {
            $terminate->handle($this->accountId, $this->terminate_note);
        } catch (ModelNotFoundException) {
            $this->toastDeletedMeanwhile();

            return;
        }

        $this->finish('terminate', $this->disconnect() ? __('Customer terminated and disconnected.') : __('Customer terminated.'));
    }

    /**
     * The action's permission, and a customer that is not deleted.
     */
    private function authorizeLive(string $ability): void
    {
        $this->authorize($ability, $this->account);
        abort_if($this->account->trashed(), 403);
    }

    /**
     * A plan that exists and does not wait for the first login.
     */
    private function planRule(): Exists
    {
        return Rule::exists('plans', 'id')->withoutTrashed()->where('hotspot_starts_at_first_login', false);
    }

    private function fillChangeDefaults(): void
    {
        $this->change_base_expiry = RechargeAccount::expiryKey($this->account);
        $plan = $this->change_plan_id === '' ? null : Plan::query()->find((int) $this->change_plan_id);
        $this->change_expiry = $plan === null ? '' : ExpiryCalculator::fromSettings()->next(null, $plan->validity, $plan->validity_unit, now())->format('Y-m-d\TH:i');
        $this->change_amount = $plan === null ? '' : (string) $plan->price;
    }

    private function fillAdvanceDefaults(): void
    {
        $plan = $this->advance_plan_id === '' ? null : Plan::query()->find((int) $this->advance_plan_id);
        $starts = $this->account->expiry;
        $this->advance_expiry = $plan === null || $starts === null ? ''
            : ExpiryCalculator::fromSettings()->next($starts, $plan->validity, $plan->validity_unit, $starts->copy()->subSecond())->format('Y-m-d\TH:i');
        $this->advance_amount = $plan === null ? '' : (string) $plan->price;
    }

    /**
     * Where the current period started: when the last recharge was made, or the old expiry it started from (an
     * advance or auto renewal, or a recharge before the expiry). A change of plan starts when it was made.
     */
    private function periodStart(): ?CarbonInterface
    {
        $last = Recharge::query()->where('account_id', $this->accountId)->latest('id')->first();

        if ($last === null) {
            return null;
        }

        if ($last->kind !== RechargeKind::ChangePlan && $last->old_expiry !== null && $last->old_expiry->greaterThan($last->created_at)) {
            return $last->old_expiry;
        }

        return $last->created_at;
    }

    /**
     * "in 3 days", "today" or "2 days ago", by calendar day.
     */
    private static function relativeDays(CarbonInterface $date): string
    {
        $days = (int) now()->startOfDay()->diffInDays($date->copy()->startOfDay(), false);

        return match (true) {
            $days === 0 => __('today'),
            $days > 0 => trans_choice('in :count day|in :count days', $days),
            default => trans_choice(':count day ago|:count days ago', -$days),
        };
    }

    /**
     * Disconnects an online customer (after the commit) so a change applies now.
     */
    private function disconnect(): bool
    {
        return DisconnectAccount::dispatchIfOnline($this->account->username);
    }

    private function finish(?string $dialog, string $message, bool $disconnected = false, bool $wasInactive = false): void
    {
        $this->refreshAccount();

        if ($dialog !== null) {
            $this->dispatch('modal-close', name: $dialog);
        }

        if ($disconnected) {
            $message .= ' '.__('They are disconnected so the change applies now.');
        } elseif ($wasInactive && $this->account->status_id === AccountStatus::Active) {
            $message .= ' '.__('The customer is Active now.');
        }

        $this->dispatch('account-changed')->to('pages::customers.show');
        $this->toast($message);
    }

    private function toastDeletedMeanwhile(): void
    {
        $this->refreshAccount();
        $this->toast(__('This customer was deleted meanwhile. Reload the page.'), 'danger');
    }

    private function nullIfEmpty(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private function dateFormat(): string
    {
        return AppSetting::valueOf('date_format', 'd/m/Y').' H:i';
    }
};
