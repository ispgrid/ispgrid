<?php

use App\Actions\Billing\IssueInvoice;
use App\Actions\Customers\AddCustomerService;
use App\Actions\Customers\CancelServiceRenewal;
use App\Actions\Customers\ReleaseServiceIp;
use App\Actions\Customers\RenewCustomerService;
use App\Billing\BillingSettings;
use App\Billing\Decimal;
use App\Billing\InvoicePolicy;
use App\Billing\LineInput;
use App\Enums\DisconnectReason;
use App\Enums\IpRangeKind;
use App\Enums\ServiceKind;
use App\Exceptions\CannotAddService;
use App\Exceptions\CannotCancelServiceRenewal;
use App\Jobs\DisconnectAccount;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\ShowsToasts;
use App\Livewire\Concerns\TakesPayment;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\CustomerService;
use App\Models\Ip;
use App\Models\Item;
use App\Support\Currency;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The Services card at the top of the customer's Billing tab: the services the customer takes (a static IP, OTT),
 * each with its own paid-until date, and Add service, Renew, Cancel renewal, End service and Release IP now for
 * staff with Manage customer services. Disconnects go out after each action's commit. Read-only for a deleted
 * customer.
 */
new class extends Component
{
    use AuthorizesEveryRequest, ShowsToasts, TakesPayment;

    private const MONTHS = ['1', '3', '6', '12'];

    #[Locked]
    public int $accountId;

    public bool $show_ended = false;

    /**
     * The service being renewed, cancelled or ended.
     */
    #[Locked]
    public ?int $serviceId = null;

    public string $item_id = '';

    public string $ip_address = '';

    public string $months = '1';

    public string $start_date = '';

    public string $amount = '';

    public string $reason = '';

    public function mount(int $accountId): void
    {
        $this->accountId = $accountId;
        $this->authorizeAccess();
    }

    protected function authorizeAccess(): void
    {
        $this->authorize('view-customers');
    }

    #[Computed]
    public function account(): Account
    {
        return Account::withTrashed()->with('ip')->findOrFail($this->accountId);
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('manage-customer-services') && ! $this->account->trashed();
    }

    #[Computed]
    public function canPrice(): bool
    {
        return Gate::allows('set-customer-pricing');
    }

    /**
     * @return Collection<int, CustomerService>
     */
    #[Computed]
    public function services(): Collection
    {
        return CustomerService::query()->where('account_id', $this->accountId)
            ->when(! $this->show_ended, fn ($query) => $query->whereNull('ended_at'))
            ->with(['item', 'ip', 'latestRenewal'])
            ->orderByRaw('ended_at is not null')->orderBy('paid_until')
            ->get();
    }

    #[Computed]
    public function hasEnded(): bool
    {
        return CustomerService::query()->where('account_id', $this->accountId)->whereNotNull('ended_at')->exists();
    }

    /**
     * The rows as the card shows them.
     *
     * @return list<array{id: int, name: string, ip: ?string, state: string, label: string, color: string, paid_until: string, renew: bool, cancel: bool, end: bool, release: bool}>
     */
    #[Computed]
    public function rows(): array
    {
        $format = (string) AppSetting::valueOf('date_format', 'd/m/Y');
        $now = now();

        return $this->services->map(function (CustomerService $service) use ($format, $now): array {
            $state = $service->state($now);

            return [
                'id' => $service->id,
                'name' => $service->item->name,
                'ip' => $service->isStaticIp() ? $service->ip?->address : null,
                'state' => $state,
                'label' => match ($state) {
                    'active' => __('Active'),
                    'held' => $service->held_until !== null
                        ? __('Expired, held until :date', ['date' => $service->held_until->format($format)])
                        : ($service->expired_at === null || BillingSettings::current()->staticIpHoldDays === 0 ? __('Expired, held until released') : __('Expired, held')),
                    'expired' => __('Expired'),
                    default => __('Ended'),
                },
                'color' => match ($state) {
                    'active' => 'green',
                    'held', 'expired' => 'amber',
                    default => 'zinc',
                },
                'paid_until' => $service->paid_until->format($format.' H:i'),
                'renew' => $state !== 'ended',
                'cancel' => $state !== 'ended' && $service->latestRenewal?->old_paid_until !== null,
                'end' => $state !== 'ended',
                'release' => $state === 'held',
            ];
        })->all();
    }

    /**
     * Active service items, by name.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function itemOptions(): array
    {
        return Item::query()->active()->services()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function monthOptions(): array
    {
        return collect(self::MONTHS)->mapWithKeys(fn (string $months): array => [$months => trans_choice(':count month|:count months', (int) $months)])->all();
    }

    #[Computed]
    public function pickedItem(): ?Item
    {
        return ctype_digit($this->item_id) ? Item::query()->active()->services()->find((int) $this->item_id) : null;
    }

    #[Computed]
    public function pickedIsStaticIp(): bool
    {
        return $this->pickedItem?->service_kind === ServiceKind::StaticIp;
    }

    /**
     * The service being renewed, cancelled or ended, by name.
     */
    #[Computed]
    public function serviceName(): string
    {
        return $this->serviceId === null ? '' : (string) $this->ownService($this->serviceId)->item->name;
    }

    /**
     * Whether an invoice goes out (the payment fields then show) and its total with tax.
     */
    #[Computed]
    public function invoiced(): bool
    {
        return InvoicePolicy::invoicesRecharge($this->account, BillingSettings::current());
    }

    #[Computed]
    public function invoiceTotal(): string
    {
        $item = $this->serviceId === null ? $this->pickedItem : $this->ownService($this->serviceId)->item;

        if ($item === null || ! $this->invoiced || preg_match(Currency::amountPattern(), $this->chargedAmount($item)) !== 1) {
            return '0';
        }

        return IssueInvoice::draft($this->account, [new LineInput($item->name, null, null, '1', $this->chargedAmount($item), '0', $item->taxable)],
            false, BillingSettings::current())->total;
    }

    #[Computed]
    public function invoiceTotalText(): string
    {
        return Money::fromSettings()->format($this->invoiceTotal);
    }

    public function updatedShowEnded(): void
    {
        unset($this->services, $this->rows);
    }

    public function updatedItemId(): void
    {
        $item = $this->pickedItem;
        $this->ip_address = $item?->service_kind === ServiceKind::StaticIp ? (string) $this->account->ip?->address : '';
        $this->amount = $item === null ? '' : $this->priceFor($item);
    }

    public function updatedMonths(): void
    {
        $item = $this->serviceId === null ? $this->pickedItem : $this->ownService($this->serviceId)->item;
        $this->amount = $item === null ? '' : $this->priceFor($item);
    }

    public function openAdd(): void
    {
        $this->authorizeManage();
        $this->resetForm();
        $this->start_date = now()->format('Y-m-d');
        $this->dispatch('modal-open', name: 'service-add');
    }

    /**
     * The first free address of a PPPoE range, as the customer form's Next free IP gives it.
     */
    public function fillNextFreeIp(): void
    {
        $this->authorizeManage();
        $this->resetErrorBag('ip_address');

        $address = Ip::query()->free()
            ->whereHas('range', fn ($query) => $query->where('kind', IpRangeKind::Pppoe->value))
            ->orderByRaw('INET_ATON(address)')
            ->value('address');

        if ($address === null) {
            $this->addError('ip_address', __('No free address left. Add a range under Network, IP ranges.'));

            return;
        }

        $this->ip_address = $address;
    }

    public function add(AddCustomerService $addService): void
    {
        $this->authorizeManage();
        $item = $this->pickedItem;
        $this->validate([
            'item_id' => ['required', 'in:'.implode(',', array_keys($this->itemOptions))],
            'ip_address' => $this->pickedIsStaticIp ? ['required', 'ipv4'] : ['nullable'],
            'months' => ['required', 'in:'.implode(',', self::MONTHS)],
            'start_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'amount' => $this->canPrice ? ['required', 'regex:'.Currency::amountPattern()] : [],
            ...$this->paymentRules($this->invoiceTotal),
        ], ['start_date.before_or_equal' => __('A service cannot start in the future.')], [
            'item_id' => __('service'), 'ip_address' => __('IP address'), 'start_date' => __('start date'), ...$this->paymentAttributes(),
        ]);

        try {
            $addService->handle($this->account, $item, (int) $this->months, Carbon::createFromFormat('!Y-m-d', $this->start_date) ?: now(),
                $this->chargedAmount($item), $this->pickedIsStaticIp ? trim($this->ip_address) : null, $this->paymentInput($this->invoiceTotal));
        } catch (CannotAddService $exception) {
            $this->addError($exception->why === 'ip-invalid' ? 'ip_address' : 'item_id', match ($exception->why) {
                'not-pppoe' => __('A static IP is for PPPoE customers only.'),
                'has-static-ip' => __('This customer already has a static IP service. Renew it or end it first.'),
                'not-service' => __('Pick an active service.'),
                default => $exception->getMessage(),
            });

            return;
        }

        $this->done('service-add', __('Service added.'));
    }

    public function openRenew(int $serviceId): void
    {
        $this->authorizeManage();
        $service = $this->ownService($serviceId);
        abort_if($service->ended_at !== null, 404);

        $this->resetForm();
        $this->serviceId = $service->id;
        $this->amount = $this->priceFor($service->item);
        $this->dispatch('modal-open', name: 'service-renew');
    }

    public function renew(RenewCustomerService $renewService): void
    {
        $this->authorizeManage();
        abort_if($this->serviceId === null, 404);
        $service = $this->ownService($this->serviceId);
        abort_if($service->ended_at !== null, 404);

        $this->validate([
            'months' => ['required', 'in:'.implode(',', self::MONTHS)],
            'amount' => $this->canPrice ? ['required', 'regex:'.Currency::amountPattern()] : [],
            ...$this->paymentRules($this->invoiceTotal),
        ], [], $this->paymentAttributes());

        $disconnect = $renewService->handle($service, (int) $this->months, $this->chargedAmount($service->item), $this->paymentInput($this->invoiceTotal));

        if ($disconnect) {
            DisconnectAccount::dispatchIfOnline($this->account->username, DisconnectReason::StaticIpRenewed);
        }

        $this->done('service-renew', $disconnect
            ? __('Service renewed. The customer is disconnected so they get the fixed IP back.')
            : __('Service renewed.'));
    }

    public function openCancelRenewal(int $serviceId): void
    {
        $this->authorizeManage();
        $service = $this->ownService($serviceId);

        $this->resetForm();
        $this->serviceId = $service->id;
        $this->dispatch('modal-open', name: 'service-cancel');
    }

    public function cancelRenewal(CancelServiceRenewal $cancel): void
    {
        $this->authorizeManage();
        abort_if($this->serviceId === null, 404);
        $service = $this->ownService($this->serviceId);
        $this->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => __('reason')]);
        $renewalId = $service->latestRenewal?->id;

        try {
            abort_if($renewalId === null, 404);
            $disconnect = $cancel->handle($renewalId, $this->reason);
        } catch (CannotCancelServiceRenewal $exception) {
            $this->addError('reason', match ($exception->why) {
                'payments' => __('Its invoice has payments. Remove them first.'),
                'first' => __('This is the first sale of the service. End the service and cancel its invoice instead.'),
                default => __('Only the latest renewal can be cancelled.'),
            });

            return;
        }

        if ($disconnect) {
            DisconnectAccount::dispatchIfOnline($this->account->username, DisconnectReason::StaticIpExpired);
        }

        $this->done('service-cancel', __('Renewal cancelled.'));
    }

    public function openEnd(int $serviceId): void
    {
        $this->authorizeManage();
        $service = $this->ownService($serviceId);
        abort_if($service->ended_at !== null, 404);

        $this->resetForm();
        $this->serviceId = $service->id;
        $this->dispatch('modal-open', name: 'service-end');
    }

    /**
     * Ends the service; a static IP goes back to the free pool at once.
     */
    public function end(ReleaseServiceIp $release): void
    {
        $this->authorizeManage();
        abort_if($this->serviceId === null, 404);
        $service = $this->ownService($this->serviceId);
        $this->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => __('reason')]);

        if ($release->handle($service, trim($this->reason), 'service-ended')) {
            DisconnectAccount::dispatchIfOnline($this->account->username, DisconnectReason::StaticIpReleased);
        }

        $this->done('service-end', __('Service ended.'));
    }

    /**
     * Release IP now: an expired static IP held for the customer goes back to the free pool and the service ends.
     */
    public function releaseIp(int $serviceId, ReleaseServiceIp $release): void
    {
        $this->authorizeManage();
        $service = $this->ownService($serviceId);

        if ($service->state(now()) !== 'held') {
            $this->toast(__('Only an expired, held static IP can be released.'), 'danger');

            return;
        }

        $release->handle($service, 'Released by staff', 'service-ip-released');
        $this->done(null, __('IP released.'));
    }

    private function authorizeManage(): void
    {
        $this->authorize('manage-customer-services');
        abort_if(Account::onlyTrashed()->whereKey($this->accountId)->exists(), 403);
    }

    private function ownService(int $serviceId): CustomerService
    {
        return CustomerService::query()->with('item', 'latestRenewal')->where('account_id', $this->accountId)->findOrFail($serviceId);
    }

    /**
     * The item price for the chosen months.
     */
    private function priceFor(Item $item): string
    {
        $months = in_array($this->months, self::MONTHS, true) ? $this->months : '1';

        return Decimal::trim(Decimal::round(Decimal::mul((string) $item->price, $months), Currency::decimals()));
    }

    /**
     * What is charged: the typed amount with Set customer pricing, else the item price for the months.
     */
    private function chargedAmount(Item $item): string
    {
        return $this->canPrice ? trim($this->amount) : $this->priceFor($item);
    }

    private function done(?string $modal, string $message): void
    {
        if ($modal !== null) {
            $this->dispatch('modal-close', name: $modal);
        }

        $this->resetForm();
        unset($this->services, $this->rows, $this->hasEnded, $this->account);
        $this->dispatch('services-changed');
        $this->toast($message);
    }

    private function resetForm(): void
    {
        $this->reset('serviceId', 'item_id', 'ip_address', 'months', 'start_date', 'amount', 'reason');
        $this->resetErrorBag();
        $this->resetPayment();
    }
};
