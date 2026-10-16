<?php

use App\Actions\Radius\ReplaceRadiusValue;
use App\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Pool;
use App\Models\RadiusSetting;
use App\Rules\NotUsedByTrashed;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Pools')] class extends Component
{
    use AuthorizesEveryRequest, ShowsToasts;

    public ?int $editingId = null;

    public string $name = '';

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
        $this->authorize('viewAny', Pool::class);
    }

    /**
     * @return Collection<int, Pool>
     */
    #[Computed]
    public function pools(): Collection
    {
        return Pool::withTrashed()
            ->withCount(['plans', 'accounts'])
            ->orderByRaw('deleted_at is not null')
            ->orderBy('name')
            ->get();
    }

    /**
     * The pool FreeRADIUS gives inactive, suspended and expired customers (settings.disabled_users_pool).
     */
    #[Computed]
    public function disabledPool(): ?string
    {
        return RadiusSetting::query()->value('disabled_users_pool');
    }

    public function create(): void
    {
        $this->authorize('create', Pool::class);

        $this->resetForm();
        $this->dispatch('modal-open', name: 'pool-form');
    }

    public function edit(int $poolId): void
    {
        $pool = Pool::query()->findOrFail($poolId);
        $this->authorize('update', $pool);

        $this->resetForm();
        $this->editingId = $pool->id;
        $this->name = $pool->name;
        $this->description = (string) $pool->description;
        $this->dispatch('modal-open', name: 'pool-form');
    }

    /**
     * A rename is written into the Framed-Pool rows of the plans and customers that use the pool, in the same
     * transaction. Online customers get the new name at their next login.
     */
    public function save(ReplaceRadiusValue $replace): void
    {
        $pool = $this->editingId === null ? null : Pool::query()->findOrFail($this->editingId);
        $this->authorize($pool === null ? 'create' : 'update', $pool ?? Pool::class);

        $validated = $this->validate($this->rules($pool?->id), [
            'name.regex' => __('Use letters, digits and . _ - only, as on the NAS.'),
        ]);

        if ($pool !== null && $pool->name !== $validated['name'] && $pool->name === $this->disabledPool) {
            $this->addError('name', __('The FreeRADIUS settings use this pool as the disabled pool. Change it there first.'));

            return;
        }

        $attributes = ['name' => $validated['name'], 'description' => $validated['description'] ?: null];

        $renamed = DB::transaction(function () use ($pool, $attributes, $replace): bool {
            if ($pool === null) {
                Pool::query()->create($attributes);

                return false;
            }

            $oldName = $pool->name;
            $pool->update($attributes);

            if (! $pool->wasChanged('name')) {
                return false;
            }

            $replace->handle('radgroupreply', $pool->plans()->pluck('slug'), 'Framed-Pool', $oldName, $pool->name);
            $replace->handle('radreply', $pool->accounts()->pluck('username'), 'Framed-Pool', $oldName, $pool->name);

            return true;
        }, attempts: 3);

        $this->dispatch('modal-close', name: 'pool-form');
        $this->resetForm();
        $this->toast($renamed
            ? __('Pool renamed. Rename it on the NAS too; online customers get the new name at their next login.')
            : __('Pool saved.'));
    }

    /**
     * Soft delete, refused while a plan or customer uses the pool or it is the disabled pool.
     */
    public function delete(int $poolId): void
    {
        $pool = Pool::query()->withCount(['plans', 'accounts'])->findOrFail($poolId);
        $this->authorize('delete', $pool);

        if ($pool->name === $this->disabledPool) {
            $this->toast(__('The FreeRADIUS settings use this pool as the disabled pool.'), 'danger');

            return;
        }

        if ($pool->plans_count + $pool->accounts_count > 0) {
            $this->toast(__('Used by :plans and :customers. Move them to another pool first.', [
                'plans' => trans_choice(':count plan|:count plans', $pool->plans_count),
                'customers' => trans_choice(':count customer|:count customers', $pool->accounts_count),
            ]), 'danger');

            return;
        }

        $pool->delete();

        $this->toast(__('Pool deleted.'));
    }

    public function restore(int $poolId): void
    {
        $pool = Pool::onlyTrashed()->findOrFail($poolId);
        $this->authorize('restore', $pool);

        $pool->restore();

        $this->toast(__('Pool restored.'));
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'description');
        $this->resetErrorBag();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(?int $ignoreId): array
    {
        return [
            'name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.-]+$/D',
                Rule::unique(Pool::class, 'name')->withoutTrashed()->ignore($ignoreId),
                new NotUsedByTrashed(Pool::class, 'name', __('A deleted pool uses this name. Restore it instead.'), $ignoreId),
            ],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
};
