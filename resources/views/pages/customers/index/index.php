<?php

use App\Enums\AccountStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceType;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\ConnectionType;
use App\Models\Nas;
use App\Models\Plan;
use App\Models\Radacct;
use App\Support\Hotspot;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Customers')] class extends Component
{
    use AuthorizesEveryRequest, WithPagination;

    private const FILTERS = ['search', 'status', 'plan', 'type', 'router', 'connection', 'expiring', 'expired', 'norenewal', 'disabled', 'created', 'renewed', 'staticip', 'dues'];

    /**
     * Filters the dashboard links use that have no control on this page, cleared together.
     */
    private const EXTRA_FILTERS = ['norenewal', 'disabled', 'created', 'renewed', 'staticip', 'dues'];

    #[Url]
    public string $search = '';

    /**
     * '' (all live customers), a status number, or 'deleted'.
     */
    #[Url]
    public string $status = '';

    #[Url]
    public string $plan = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $router = '';

    #[Url]
    public string $connection = '';

    /**
     * '' (any expiry), '1' (within 7 days) or 'today'.
     */
    #[Url]
    public string $expiring = '';

    /**
     * '' (any expiry), '1' (expired) or '7' (in the last 7 days).
     */
    #[Url]
    public string $expired = '';

    #[Url]
    public bool $norenewal = false;

    #[Url]
    public bool $disabled = false;

    /**
     * '', 'today' or 'month'.
     */
    #[Url]
    public string $created = '';

    #[Url]
    public bool $renewed = false;

    #[Url]
    public bool $staticip = false;

    /**
     * Customers who owe something on an invoice (the dashboard's Dues card links here). Needs View invoices.
     */
    #[Url]
    public bool $dues = false;

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    /**
     * Who may use this page: checked when it opens and on every later request (AuthorizesEveryRequest).
     */
    protected function authorizeAccess(): void
    {
        $this->authorize('viewAny', Account::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, self::FILTERS, true)) {
            $this->resetPage();
        }
    }

    public function clearExtraFilters(): void
    {
        $this->reset(self::EXTRA_FILTERS);
        $this->resetPage();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function expiringChoices(): array
    {
        return ['' => __('Any expiry'), '1' => __('Expiring within 7 days'), 'today' => __('Expiring today')];
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function expiredChoices(): array
    {
        return ['' => __('Any expiry'), '1' => __('Expired'), '7' => __('Expired in the last 7 days')];
    }

    /**
     * Labels of the active filters that have no control on this page.
     *
     * @return list<string>
     */
    #[Computed]
    public function extraFilters(): array
    {
        $labels = [];

        if ($this->norenewal) {
            $labels[] = __('Without a renewal');
        }

        if ($this->disabled) {
            $labels[] = __('Disabled');
        }

        if ($this->created === 'today') {
            $labels[] = __('New today');
        }

        if ($this->created === 'month') {
            $labels[] = __('New this month');
        }

        if ($this->renewed) {
            $labels[] = __('Renewed today');
        }

        if ($this->staticip) {
            $labels[] = __('Static IP');
        }

        if ($this->dues && Gate::allows('view-invoices')) {
            $labels[] = __('Has dues');
        }

        return $labels;
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function statuses(): array
    {
        $statuses = ['' => __('All customers')];

        foreach (AccountStatus::cases() as $status) {
            $statuses[(string) $status->value] = $status->label();
        }

        return $statuses + ['deleted' => __('Deleted')];
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function plans(): array
    {
        return Plan::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function types(): array
    {
        return collect(Hotspot::serviceTypes())
            ->mapWithKeys(fn (ServiceType $type): array => [$type->value => $type->label()])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function routers(): array
    {
        return Nas::query()->orderBy('shortname')->get()
            ->mapWithKeys(fn (Nas $router): array => [$router->id => $router->shortname ?? $router->nasname])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function connectionTypes(): array
    {
        return ConnectionType::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    #[Computed]
    public function accounts(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Account::query()
            ->when($this->status === 'deleted', fn (Builder $query) => $query->onlyTrashed())
            ->when(ctype_digit($this->status), fn (Builder $query) => $query->where('status_id', (int) $this->status))
            ->when($this->plan !== '', fn (Builder $query) => $query->where('plan_id', (int) $this->plan))
            ->when($this->type !== '', fn (Builder $query) => $query->where('service_type', $this->type))
            ->when($this->router !== '', fn (Builder $query) => $query->where('nas_id', (int) $this->router))
            ->when($this->connection !== '', fn (Builder $query) => $query->where('connection_type_id', (int) $this->connection))
            ->when($this->expiring === '1', fn (Builder $query) => $query->whereBetween('expiry', [now(), now()->addDays(7)]))
            ->when($this->expiring === 'today', fn (Builder $query) => $query->whereBetween('expiry', [now(), now()->endOfDay()]))
            ->when($this->expired === '1', fn (Builder $query) => $query->where('expiry', '<', now()))
            ->when($this->expired === '7', fn (Builder $query) => $query->where('expiry', '>=', now()->subDays(7))->where('expiry', '<', now()))
            ->when($this->norenewal, fn (Builder $query) => $query->where('auto_renew', false)->doesntHave('waitingRenewal'))
            ->when($this->disabled, fn (Builder $query) => $query->whereIn('status_id', [AccountStatus::Inactive, AccountStatus::Suspended]))
            ->when($this->created === 'today', fn (Builder $query) => $query->where('created_at', '>=', now()->startOfDay()))
            ->when($this->created === 'month', fn (Builder $query) => $query->where('created_at', '>=', now()->startOfMonth()))
            ->when($this->renewed, fn (Builder $query) => $query->whereExists(fn ($query) => $query->select(DB::raw(1))->from('recharges')
                ->whereColumn('recharges.account_id', 'accounts.id')->where('recharges.created_at', '>=', now()->startOfDay())))
            ->when($this->staticip, fn (Builder $query) => $query->whereNotNull('ip_id'))
            ->when($this->dues && Gate::allows('view-invoices'), fn (Builder $query) => $query->whereHas('invoices', fn (Builder $invoices) => $invoices
                ->where('status', '!=', InvoiceStatus::Cancelled)->where('balance', '>', 0)))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('username', 'like', "%{$search}%")
                ->orWhere('fullname', 'like', "%{$search}%")
                ->orWhere('mobile', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhereHas('ip', fn (Builder $query) => $query->where('address', $search))))
            ->with([
                'plan' => fn ($query) => $query->withTrashed()->select('id', 'name'),
                'nas' => fn ($query) => $query->withTrashed()->select('id', 'shortname', 'nasname'),
            ])
            ->orderBy('username')
            ->paginate(50);
    }

    /**
     * @return list<array{id: int, username: string, name: string, login: ?string, online: bool, type: string, plan: ?string, status: string, color: string, expiry: ?string, pending: bool, expired: bool, router: ?string, trashed: bool}>
     */
    #[Computed]
    public function rows(): array
    {
        $format = AppSetting::valueOf('date_format', 'd/m/Y').' H:i';
        $accounts = collect($this->accounts->items());
        $online = $accounts->isEmpty() ? [] : array_flip(Radacct::query()->open()
            ->whereIn('username', $accounts->pluck('username'))->distinct()->pluck('username')->all());

        return $accounts->map(fn (Account $account): array => [
            'id' => $account->id,
            'username' => $account->username,
            ...$account->heading(),
            'online' => isset($online[$account->username]),
            'type' => $account->service_type->label(),
            'plan' => $account->plan?->name,
            'status' => $account->status_id->label(),
            'color' => $account->status_id->color(),
            'expiry' => $account->expiry?->format($format),
            'pending' => $account->hotspot_pending_validity !== null,
            'expired' => $account->expiry?->isPast() ?? false,
            'router' => $account->nas === null ? null : ($account->nas->shortname ?? $account->nas->nasname),
            'trashed' => $account->trashed(),
        ])->all();
    }
};
