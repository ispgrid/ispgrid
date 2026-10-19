<?php

use App\Enums\IpRangeKind;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Ip;
use App\Models\IpRange;
use App\Support\IpAddress;
use App\Support\Ipv4Block;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('IP ranges')] class extends Component
{
    use AuthorizesEveryRequest, ShowsToasts;

    /**
     * The most addresses added at once: a /20.
     */
    private const LARGEST_SIZE = 4096;

    public ?int $editingId = null;

    public string $name = '';

    public string $kind = 'ipoe';

    public string $network = '';

    public bool $leave_out_edges = false;

    public string $gateway = '';

    public string $dns1 = '';

    public string $dns2 = '';

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    /**
     * Who may use this page: checked when it opens and on every later request (AuthorizesEveryRequest).
     */
    protected function authorizeAccess(): void
    {
        $this->authorize('viewAny', IpRange::class);
    }

    #[Computed]
    public function isPppoe(): bool
    {
        return $this->kind === IpRangeKind::Pppoe->value;
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function kinds(): array
    {
        return [
            IpRangeKind::Ipoe->value => __('IPoE (a subnet on a router port)'),
            IpRangeKind::Pppoe->value => __('PPPoE (routed to the sessions)'),
        ];
    }

    /**
     * @return Collection<int, IpRange>
     */
    #[Computed]
    public function ranges(): Collection
    {
        return IpRange::withTrashed()
            ->withCount(['ips', 'ips as given_out_count' => fn (Builder $query) => $query->givenOut()])
            ->orderByRaw('deleted_at is not null')
            ->orderBy('name')
            ->get();
    }

    public function create(): void
    {
        $this->authorize('create', IpRange::class);

        $this->resetForm();
        $this->dispatch('modal-open', name: 'ip-range-form');
    }

    public function edit(int $rangeId): void
    {
        $range = IpRange::query()->findOrFail($rangeId);
        $this->authorize('update', $range);

        $this->resetForm();
        $this->editingId = $range->id;
        $this->name = $range->name;
        $this->kind = $range->kind->value;
        $this->network = $range->network;
        $this->leave_out_edges = $range->leave_out_edges;
        $this->gateway = (string) $range->gateway;
        $this->dns1 = (string) $range->dns1;
        $this->dns2 = (string) $range->dns2;
        $this->dispatch('modal-open', name: 'ip-range-form');
    }

    public function save(): void
    {
        $range = $this->editingId === null ? null : IpRange::query()->findOrFail($this->editingId);
        $this->authorize($range === null ? 'create' : 'update', $range ?? IpRange::class);

        foreach (['gateway', 'dns1', 'dns2'] as $field) {
            $this->{$field} = IpAddress::normalize($this->{$field}) ?? trim($this->{$field});
        }

        if ($range === null) {
            $this->network = (string) (Ipv4Block::parse($this->network) ?? trim($this->network));
        }

        $validated = $this->validate($this->rules($range));
        $kind = IpRangeKind::from($validated['kind']);
        $isIpoe = $kind === IpRangeKind::Ipoe;
        $details = [
            'name' => $validated['name'],
            'kind' => $kind,
            'leave_out_edges' => ! $isIpoe && $validated['leave_out_edges'] && Ipv4Block::parse($range?->network ?? $validated['network'])->isSubnet(),
            'gateway' => $isIpoe ? ($validated['gateway'] ?: null) : null,
            'dns1' => $isIpoe ? ($validated['dns1'] ?: null) : null,
            'dns2' => $isIpoe ? ($validated['dns2'] ?: null) : null,
        ];

        if ($range === null) {
            $this->addRange($validated['network'], $details);
            $this->toast(__('IP range added.'));
        } elseif ($this->updateRange($range, $details)) {
            $this->toast(__('IP range saved.'));
        } else {
            return;
        }

        $this->dispatch('modal-close', name: 'ip-range-form');
        $this->resetForm();
    }

    /**
     * Soft delete with all its addresses (same timestamp), refused while any address is given out.
     */
    public function delete(int $rangeId): void
    {
        $range = IpRange::query()->findOrFail($rangeId);
        $this->authorize('delete', $range);

        $givenOut = $range->ips()->givenOut()->count();

        if ($givenOut > 0) {
            $this->toast(trans_choice(
                ':count address of this range is given to a customer.|:count addresses of this range are given to customers.',
                $givenOut,
            ), 'danger');

            return;
        }

        DB::transaction(function () use ($range): void {
            $range->delete();
            $range->ips()->update(['deleted_at' => $range->deleted_at]);
        }, attempts: 3);

        $this->toast(__('IP range deleted.'));
    }

    /**
     * Brings back the range and the addresses its delete removed, not those taken out one by one before.
     */
    public function restore(int $rangeId): void
    {
        $range = IpRange::onlyTrashed()->findOrFail($rangeId);
        $this->authorize('restore', $range);

        DB::transaction(function () use ($range): void {
            Ip::onlyTrashed()
                ->where('ip_range_id', $range->id)
                ->where('deleted_at', $range->deleted_at)
                ->update(['deleted_at' => null]);
            $range->restore();
        }, attempts: 3);

        $this->toast(__('IP range restored.'));
    }

    /**
     * @param  array{name: string, kind: IpRangeKind, leave_out_edges: bool, gateway: ?string, dns1: ?string, dns2: ?string}  $details
     */
    private function addRange(string $network, array $details): void
    {
        DB::transaction(function () use ($network, $details): void {
            $range = IpRange::query()->create($details + ['network' => $network]);
            $now = now();

            collect($range->usableAddresses())
                ->reject(fn (string $address): bool => $address === $range->gateway)
                ->chunk(500)
                ->each(fn (SupportCollection $chunk) => Ip::query()->insert($chunk->map(fn (string $address): array => [
                    'ip_range_id' => $range->id,
                    'address' => $address,
                    'is_used' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->values()->all()));
        }, attempts: 3);
    }

    /**
     * Saves the range. A new kind or tick adds or takes out the first and last address of a subnet; a new gateway
     * leaves the inventory and the old one comes back.
     *
     * @param  array{name: string, kind: IpRangeKind, leave_out_edges: bool, gateway: ?string, dns1: ?string, dns2: ?string}  $details
     * @return bool false when an address that would leave the inventory is given to a customer (nothing saved)
     */
    private function updateRange(IpRange $range, array $details): bool
    {
        $oldGateway = $range->gateway;
        $newGateway = $details['gateway'];

        if ($newGateway !== null && $newGateway !== $oldGateway
            && Ip::query()->where('address', $newGateway)->givenOut()->exists()) {
            $this->addError('gateway', __(':address is given to a customer. Pick another gateway.', ['address' => $newGateway]));

            return false;
        }

        $before = $range->usableAddresses();
        $after = (clone $range)->fill(['kind' => $details['kind'], 'leave_out_edges' => $details['leave_out_edges']])->usableAddresses();
        $leaving = array_values(array_diff($before, $after));
        $coming = array_values(array_diff($after, $before));
        $givenOut = $leaving === [] ? null : Ip::query()->whereIn('address', $leaving)->givenOut()->value('address');

        if ($givenOut !== null) {
            $this->addError($range->kind === $details['kind'] ? 'leave_out_edges' : 'kind', __(':address is given to a customer, so it cannot leave the inventory.', ['address' => $givenOut]));

            return false;
        }

        DB::transaction(function () use ($range, $details, $oldGateway, $newGateway, $leaving, $coming): void {
            $range->update($details);

            Ip::query()->whereIn('address', $leaving)->get()->each->delete();

            foreach ($coming as $address) {
                $this->putBack($range, $address);
            }

            if (! $range->wasChanged('gateway')) {
                return;
            }

            if ($newGateway !== null) {
                Ip::query()->where('address', $newGateway)->first()?->delete();
            }

            if ($oldGateway !== null) {
                $this->putBack($range, $oldGateway);
            }
        }, attempts: 3);

        return true;
    }

    /**
     * Puts an address of the range (back) into the inventory.
     */
    private function putBack(IpRange $range, string $address): void
    {
        $ip = Ip::withTrashed()->where('address', $address)->first();

        if ($ip === null) {
            Ip::query()->create(['ip_range_id' => $range->id, 'address' => $address]);
        } elseif ($ip->trashed()) {
            $ip->restore();
        }
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'kind', 'network', 'leave_out_edges', 'gateway', 'dns1', 'dns2');
        $this->resetErrorBag();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(?IpRange $range): array
    {
        $block = Ipv4Block::parse($range?->network ?? $this->network);
        $cidr = $block?->cidr;

        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', Rule::enum(IpRangeKind::class), function (string $attribute, string $value, Closure $fail) use ($range, $block): void {
                if ($range !== null && $value === IpRangeKind::Ipoe->value && $block !== null && ! $block->isSubnet()) {
                    $fail(__('A first-to-last range can only be PPPoE.'));
                }
            }],
            'leave_out_edges' => ['boolean'],
            'gateway' => ['nullable'],
            'dns1' => ['nullable'],
            'dns2' => ['nullable'],
        ];

        if (! $this->isPppoe) {
            $rules['gateway'] = ['nullable', 'ipv4', function (string $attribute, string $value, Closure $fail) use ($cidr): void {
                if ($cidr !== null && ! $cidr->isHost($value)) {
                    $fail(__('The gateway must be a host address inside :network.', ['network' => (string) $cidr]));
                }
            }];
            $rules['dns1'] = ['nullable', 'ip'];
            $rules['dns2'] = ['nullable', 'ip'];
        }

        if ($range === null) {
            $rules['network'] = ['required', 'string', function (string $attribute, string $value, Closure $fail): void {
                $this->checkNetwork($value, $fail);
            }];
        }

        return $rules;
    }

    private function checkNetwork(string $value, Closure $fail): void
    {
        $block = Ipv4Block::parse($value);

        if ($block === null || (! $this->isPppoe && ! $block->isSubnet())) {
            $fail($this->isPppoe
                ? __('Enter an IPv4 network with its prefix, e.g. 10.20.0.0/24, or a first and last address, e.g. 10.20.0.100-10.20.0.200.')
                : __('Enter an IPv4 network with its prefix, e.g. 10.20.0.0/24.'));

            return;
        }

        if ($block->size() > self::LARGEST_SIZE) {
            $fail(__('Add at most a /20 (4,096 addresses) at a time.'));

            return;
        }

        foreach (IpRange::withTrashed()->get(['id', 'name', 'network', 'deleted_at']) as $other) {
            $otherBlock = Ipv4Block::parse($other->network);

            if ($otherBlock === null || ! $block->overlaps($otherBlock)) {
                continue;
            }

            $fail($other->trashed()
                ? __('Overlaps the deleted range :name (:network). Restore it instead.', ['name' => $other->name, 'network' => $other->network])
                : __('Overlaps the range :name (:network).', ['name' => $other->name, 'network' => $other->network]));

            return;
        }
    }
};
