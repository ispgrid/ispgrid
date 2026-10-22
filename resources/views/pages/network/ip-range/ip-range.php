<?php

use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Ip;
use App\Models\IpRange;
use App\Support\Ipv4Cidr;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('IP range')] class extends Component
{
    use AuthorizesEveryRequest, ShowsToasts, WithPagination;

    #[Locked]
    public int $rangeId;

    /**
     * '' all, 'free', 'given-out' or 'removed'.
     */
    #[Url]
    public string $show = '';

    public function mount(int $ipRange): void
    {
        $this->rangeId = $ipRange;
        $this->authorizeAccess();
    }

    /**
     * Who may use this page: checked when it opens and on every later request (AuthorizesEveryRequest).
     */
    protected function authorizeAccess(): void
    {
        $this->authorize('view', $this->range);
    }

    public function updatedShow(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function range(): IpRange
    {
        return IpRange::withTrashed()->findOrFail($this->rangeId);
    }

    /**
     * What a technician needs to set up a device on this range.
     *
     * @return array{network: string, mask: string, gateway: ?string, dns: string, total: int, given_out: int}
     */
    #[Computed]
    public function summary(): array
    {
        return [
            'network' => $this->range->network,
            'mask' => Ipv4Cidr::parse($this->range->network)?->netmask() ?? '',
            'gateway' => $this->range->gateway,
            'dns' => collect([$this->range->dns1, $this->range->dns2])->filter()->implode(', '),
            'total' => Ip::query()->where('ip_range_id', $this->rangeId)->count(),
            'given_out' => Ip::query()->where('ip_range_id', $this->rangeId)->givenOut()->count(),
        ];
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function filters(): array
    {
        return [
            '' => __('All addresses'),
            'free' => __('Free'),
            'given-out' => __('Given out'),
            'removed' => __('Taken out of the inventory'),
        ];
    }

    #[Computed]
    public function ips(): LengthAwarePaginator
    {
        return Ip::withTrashed()
            ->where('ip_range_id', $this->rangeId)
            ->with('account:id,username,ip_id')
            ->when($this->show === 'free', fn ($query) => $query->withoutTrashed()->where('is_used', false)->whereDoesntHave('account'))
            ->when($this->show === 'given-out', fn ($query) => $query->withoutTrashed()->givenOut())
            ->when($this->show === 'removed', fn ($query) => $query->onlyTrashed())
            ->orderByRaw('INET_ATON(address)')
            ->paginate(100);
    }

    /**
     * @return list<array{model: Ip, id: int, address: string, state: 'gateway'|'removed'|'given-out'|'free', customer: ?string}>
     */
    #[Computed]
    public function rows(): array
    {
        return collect($this->ips->items())->map(fn (Ip $ip): array => [
            'model' => $ip,
            'id' => $ip->id,
            'address' => $ip->address,
            'state' => match (true) {
                $ip->address === $this->range->gateway => 'gateway',
                $ip->trashed() => 'removed',
                $ip->is_used || $ip->account !== null => 'given-out',
                default => 'free',
            },
            'customer' => $ip->account?->username,
        ])->all();
    }

    /**
     * Takes a free address out of the inventory so no customer gets it.
     */
    public function removeIp(int $ipId): void
    {
        $ip = Ip::query()->where('ip_range_id', $this->rangeId)->findOrFail($ipId);
        $this->authorize('delete', $ip);

        if ($ip->is_used || $ip->account()->exists()) {
            $this->toast(__(':address is given to a customer.', ['address' => $ip->address]), 'danger');

            return;
        }

        // The range row is locked first, so a delete of the whole range never shares this address's time.
        DB::transaction(function () use ($ipId): void {
            IpRange::withTrashed()->lockForUpdate()->findOrFail($this->rangeId);
            Ip::query()->where('ip_range_id', $this->rangeId)->findOrFail($ipId)->delete();
        }, attempts: 3);

        $this->toast(__(':address taken out of the inventory.', ['address' => $ip->address]));
    }

    public function restoreIp(int $ipId): void
    {
        $ip = Ip::onlyTrashed()->where('ip_range_id', $this->rangeId)->findOrFail($ipId);
        $this->authorize('restore', $ip);

        if ($this->range->trashed()) {
            $this->toast(__('Restore the range first.'), 'danger');

            return;
        }

        if ($ip->address === $this->range->gateway) {
            $this->toast(__(':address is the gateway of this range, so no customer may get it.', ['address' => $ip->address]), 'danger');

            return;
        }

        $ip->restore();

        $this->toast(__(':address is back in the inventory.', ['address' => $ip->address]));
    }
};
