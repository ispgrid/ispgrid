<?php

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Ip;
use App\Models\IpRange;
use App\Support\IpAddress;
use App\Support\Ipv4Cidr;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('IP ranges')] class extends Component
{
    use ShowsToasts;

    /**
     * The biggest range added at once: a /20 is 4,094 addresses.
     */
    private const LARGEST_PREFIX = 20;

    public ?int $editingId = null;

    public string $name = '';

    public string $network = '';

    public string $gateway = '';

    public string $dns1 = '';

    public string $dns2 = '';

    public function mount(): void
    {
        $this->authorize('viewAny', IpRange::class);
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
        $this->network = $range->network;
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
            $this->network = (string) (Ipv4Cidr::parse($this->network) ?? trim($this->network));
        }

        $validated = $this->validate($this->rules($range));
        $details = [
            'name' => $validated['name'],
            'gateway' => $validated['gateway'] ?: null,
            'dns1' => $validated['dns1'] ?: null,
            'dns2' => $validated['dns2'] ?: null,
        ];

        if ($range === null) {
            $this->addRange(Ipv4Cidr::parse($validated['network']), $details);
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
     * @param  array{name: string, gateway: ?string, dns1: ?string, dns2: ?string}  $details
     */
    private function addRange(Ipv4Cidr $cidr, array $details): void
    {
        DB::transaction(function () use ($cidr, $details): void {
            $range = IpRange::query()->create($details + ['network' => (string) $cidr]);
            $now = now();

            collect($cidr->hosts())
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
     * Saves name, gateway and DNS. A new gateway leaves the inventory and the old one comes back.
     *
     * @param  array{name: string, gateway: ?string, dns1: ?string, dns2: ?string}  $details
     * @return bool false when the new gateway is given to a customer (nothing saved)
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

        DB::transaction(function () use ($range, $details, $oldGateway, $newGateway): void {
            $range->update($details);

            if (! $range->wasChanged('gateway')) {
                return;
            }

            if ($newGateway !== null) {
                Ip::query()->where('address', $newGateway)->first()?->delete();
            }

            if ($oldGateway !== null) {
                $old = Ip::withTrashed()->where('address', $oldGateway)->first();

                if ($old === null) {
                    Ip::query()->create(['ip_range_id' => $range->id, 'address' => $oldGateway]);
                } elseif ($old->trashed()) {
                    $old->restore();
                }
            }
        }, attempts: 3);

        return true;
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'network', 'gateway', 'dns1', 'dns2');
        $this->resetErrorBag();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(?IpRange $range): array
    {
        $cidr = Ipv4Cidr::parse($range?->network ?? $this->network);

        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'gateway' => ['nullable', 'ipv4', function (string $attribute, string $value, Closure $fail) use ($cidr): void {
                if ($cidr !== null && ! $cidr->isHost($value)) {
                    $fail(__('The gateway must be a host address inside :network.', ['network' => (string) $cidr]));
                }
            }],
            'dns1' => ['nullable', 'ip'],
            'dns2' => ['nullable', 'ip'],
        ];

        if ($range === null) {
            $rules['network'] = ['required', 'string', function (string $attribute, string $value, Closure $fail): void {
                $this->checkNetwork($value, $fail);
            }];
        }

        return $rules;
    }

    private function checkNetwork(string $value, Closure $fail): void
    {
        $cidr = Ipv4Cidr::parse($value);

        if ($cidr === null) {
            $fail(__('Enter an IPv4 network with its prefix, e.g. 10.20.0.0/24.'));

            return;
        }

        if ($cidr->prefix < self::LARGEST_PREFIX) {
            $fail(__('Add at most a /20 (4,096 addresses) at a time.'));

            return;
        }

        foreach (IpRange::withTrashed()->get(['id', 'name', 'network', 'deleted_at']) as $other) {
            $otherCidr = Ipv4Cidr::parse($other->network);

            if ($otherCidr === null || ! $cidr->overlaps($otherCidr)) {
                continue;
            }

            $fail($other->trashed()
                ? __('Overlaps the deleted range :name (:network). Restore it instead.', ['name' => $other->name, 'network' => $other->network])
                : __('Overlaps the range :name (:network).', ['name' => $other->name, 'network' => $other->network]));

            return;
        }
    }
};
