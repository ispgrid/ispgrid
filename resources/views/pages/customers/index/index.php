<?php

use App\Concerns\AuthorizesEveryRequest;
use App\Enums\AccountStatus;
use App\Enums\ServiceType;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\ConnectionType;
use App\Models\Nas;
use App\Models\Plan;
use App\Support\Hotspot;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Customers')] class extends Component
{
    use AuthorizesEveryRequest, WithPagination;

    private const FILTERS = ['search', 'status', 'plan', 'type', 'router', 'connection', 'expiring', 'expired'];

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

    #[Url]
    public bool $expiring = false;

    #[Url]
    public bool $expired = false;

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
            ->when($this->expiring, fn (Builder $query) => $query->whereBetween('expiry', [now(), now()->addDays(7)]))
            ->when($this->expired, fn (Builder $query) => $query->where('expiry', '<', now()))
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
     * @return list<array{id: int, username: string, fullname: string, type: string, plan: ?string, status: string, color: string, expiry: ?string, pending: bool, expired: bool, router: ?string, trashed: bool}>
     */
    #[Computed]
    public function rows(): array
    {
        $format = AppSetting::valueOf('date_format', 'd/m/Y').' H:i';

        return collect($this->accounts->items())->map(fn (Account $account): array => [
            'id' => $account->id,
            'username' => $account->username,
            'fullname' => $account->fullname,
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
