<?php

use App\Actions\Radius\ReplaceRadiusValue;
use App\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\RestartsFreeRadius;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Nas;
use App\Rules\NotUsedByTrashed;
use App\Support\IpAddress;
use App\Support\RouterOsVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('NAS')] class extends Component
{
    use AuthorizesEveryRequest, RestartsFreeRadius, ShowsToasts;

    public ?int $editingId = null;

    public string $shortname = '';

    public string $nasname = '';

    public string $secret = '';

    public string $routeros_version = '';

    public string $description = '';

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    /**
     * Who may use this page: checked when it opens and on every later request (AuthorizesEveryRequest).
     */
    protected function authorizeAccess(): void
    {
        $this->authorize('viewAny', Nas::class);
    }

    /**
     * Live routers first, then deleted ones, with bound customers and the last request heard.
     *
     * @return Collection<int, Nas>
     */
    #[Computed]
    public function routers(): Collection
    {
        return Nas::withTrashed()
            ->select('nas.*')
            ->withCount('accounts')
            ->addSelect([
                // Answered requests only: isp logs requests it drops (Blast-RADIUS checks) with reply '0'.
                'last_auth_at' => DB::table('radpostauth')->selectRaw('max(authdate)')
                    ->whereColumn('radpostauth.nasipaddress', 'nas.nasname')
                    ->where('reply', '!=', '0'),
                'last_reply' => DB::table('radpostauth')->select('reply')
                    ->whereColumn('radpostauth.nasipaddress', 'nas.nasname')
                    ->orderByDesc('authdate')->orderByDesc('id')
                    ->limit(1),
                // Open sessions only, so a router's whole accounting history is never read.
                'last_acct_at' => DB::table('radacct')->selectRaw('max(coalesce(acctupdatetime, acctstarttime))')
                    ->whereColumn('radacct.nasipaddress', 'nas.nasname')
                    ->whereNull('acctstoptime'),
            ])
            ->orderByRaw('deleted_at is not null')
            ->orderBy('shortname')
            ->get();
    }

    /**
     * @return list<array{model: Nas, id: int, name: string, address: string, version: ?string, warning: ?string, customers: int, heard: ?string, silent: bool, dropping: bool, trashed: bool}>
     */
    #[Computed]
    public function rows(): array
    {
        return $this->routers->map(function (Nas $router): array {
            $heard = collect([$router->last_auth_at, $router->last_acct_at])
                ->filter()
                ->map(fn (string $at): CarbonImmutable => CarbonImmutable::parse($at))
                ->max();

            return [
                'model' => $router,
                'id' => $router->id,
                'name' => $router->shortname ?? $router->nasname,
                'address' => $router->nasname,
                'version' => $router->routeros_version,
                'warning' => $router->versionWarning(),
                'customers' => $router->accounts_count,
                'heard' => $heard?->diffForHumans(),
                'silent' => ! $router->trashed()
                    && ($heard === null || ($router->updated_at !== null && $heard->lt($router->updated_at))),
                'dropping' => ! $router->trashed() && $router->last_reply === '0',
                'trashed' => $router->trashed(),
            ];
        })->all();
    }

    /**
     * The Blast-RADIUS warning for the version being typed in the form.
     */
    #[Computed]
    public function versionWarning(): ?string
    {
        return RouterOsVersion::warningFor($this->routeros_version);
    }

    public function create(): void
    {
        $this->authorize('create', Nas::class);

        $this->resetForm();
        $this->dispatch('modal-open', name: 'router-form');
    }

    public function edit(int $routerId): void
    {
        $router = Nas::query()->findOrFail($routerId);
        $this->authorize('update', $router);

        $this->resetForm();
        $this->editingId = $router->id;
        $this->shortname = (string) $router->shortname;
        $this->nasname = $router->nasname;
        $this->secret = $router->secret;
        $this->routeros_version = (string) $router->routeros_version;
        $this->description = (string) $router->description;
        $this->dispatch('modal-open', name: 'router-form');
    }

    public function generateSecret(): void
    {
        $this->secret = Str::password(24, symbols: false);
    }

    /**
     * Saves the router; a new IP is written into the Isp-Nas rows of its bound customers in the same
     * transaction. FreeRADIUS restarts after the commit only when it has to reload the router list.
     */
    public function save(ReplaceRadiusValue $replace): void
    {
        $router = $this->editingId === null ? null : Nas::query()->findOrFail($this->editingId);
        $this->authorize($router === null ? 'create' : 'update', $router ?? Nas::class);

        $this->nasname = IpAddress::normalize($this->nasname) ?? trim($this->nasname);
        $validated = $this->validate($this->rules($router?->id), $this->messages());

        $attributes = [
            'shortname' => $validated['shortname'],
            'nasname' => $validated['nasname'],
            'secret' => $validated['secret'],
            'routeros_version' => trim($validated['routeros_version']),
            'require_message_auth' => RouterOsVersion::parse($validated['routeros_version'])->sendsMessageAuthenticator(),
            'description' => $validated['description'] ?: null,
            'type' => 'mikrotik',
        ];

        $router = DB::transaction(function () use ($router, $attributes, $replace): Nas {
            if ($router === null) {
                return Nas::query()->create($attributes);
            }

            $oldAddress = $router->nasname;
            $router->update($attributes);

            if ($router->wasChanged('nasname')) {
                $replace->handle('radcheck', $router->accounts()->pluck('username'), 'Isp-Nas', $oldAddress, $router->nasname);
            }

            return $router;
        }, attempts: 3);

        $this->dispatch('modal-close', name: 'router-form');
        $this->resetForm();

        if ($router->wasRecentlyCreated || $router->wasChanged(['nasname', 'secret'])) {
            $this->restartFreeRadiusAfter(__('NAS saved.'));

            return;
        }

        $this->toast(__('NAS saved.'));
    }

    /**
     * Soft delete, refused while a session is online through the router or customers are bound to it.
     */
    public function delete(int $routerId): void
    {
        $router = Nas::query()->withCount('accounts')->findOrFail($routerId);
        $this->authorize('delete', $router);

        $online = DB::table('radacct')->where('nasipaddress', $router->nasname)->whereNull('acctstoptime')->count();

        if ($online > 0) {
            $this->toast(trans_choice(
                ':count session is online through this NAS. Delete it when no one is online.|:count sessions are online through this NAS. Delete it when no one is online.',
                $online,
            ), 'danger');

            return;
        }

        if ($router->accounts_count > 0) {
            $this->toast(trans_choice(
                ':count customer is bound to this NAS. Change their NAS first.|:count customers are bound to this NAS. Change their NAS first.',
                $router->accounts_count,
            ), 'danger');

            return;
        }

        $router->delete();

        $this->restartFreeRadiusAfter(__('NAS deleted.'));
    }

    public function restore(int $routerId): void
    {
        $router = Nas::onlyTrashed()->findOrFail($routerId);
        $this->authorize('restore', $router);

        $router->restore();

        $this->restartFreeRadiusAfter(__('NAS restored.'));
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'shortname', 'nasname', 'secret', 'routeros_version', 'description');
        $this->resetErrorBag();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(?int $ignoreId): array
    {
        return [
            'shortname' => ['required', 'string', 'max:32', 'regex:/^[\x20-\x7E]+$/D',
                Rule::unique(Nas::class, 'shortname')->withoutTrashed()->ignore($ignoreId),
                new NotUsedByTrashed(Nas::class, 'shortname', __('A deleted NAS uses this name. Restore it instead.'), $ignoreId),
            ],
            'nasname' => ['required', 'ipv4',
                Rule::unique(Nas::class, 'nasname')->withoutTrashed()->ignore($ignoreId),
                new NotUsedByTrashed(Nas::class, 'nasname', __('A deleted NAS uses this IP address. Restore it instead.'), $ignoreId),
            ],
            'secret' => ['required', 'string', 'min:8', 'max:60', 'regex:/^[A-Za-z0-9._~!@#%^*+=-]+$/D'],
            'routeros_version' => ['required', 'string', function (string $attribute, string $value, Closure $fail): void {
                if (RouterOsVersion::parse($value) === null) {
                    $fail(__('Enter the version as the NAS shows it, e.g. 7.23.7.'));
                }
            }],
            'description' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'nasname.ipv4' => __('Enter the IPv4 address the NAS sends RADIUS from, e.g. 10.0.0.1.'),
            'shortname.regex' => __('Use English letters, digits and symbols only: FreeRADIUS loads this name.'),
            'secret.regex' => __('Use letters, digits and . _ ~ ! @ # % ^ * + = - only.'),
        ];
    }
};
