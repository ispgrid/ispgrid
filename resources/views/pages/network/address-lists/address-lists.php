<?php

use App\Actions\Radius\ReplaceRadiusValue;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\AddressList;
use App\Rules\NotUsedByTrashed;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Address lists')] class extends Component
{
    use ShowsToasts;

    public ?int $editingId = null;

    public string $name = '';

    public string $description = '';

    public function mount(): void
    {
        $this->authorize('viewAny', AddressList::class);
    }

    /**
     * @return Collection<int, AddressList>
     */
    #[Computed]
    public function lists(): Collection
    {
        return AddressList::withTrashed()
            ->withCount(['plans', 'accounts'])
            ->orderByRaw('deleted_at is not null')
            ->orderBy('name')
            ->get();
    }

    public function create(): void
    {
        $this->authorize('create', AddressList::class);

        $this->resetForm();
        $this->dispatch('modal-open', name: 'address-list-form');
    }

    public function edit(int $listId): void
    {
        $list = AddressList::query()->findOrFail($listId);
        $this->authorize('update', $list);

        $this->resetForm();
        $this->editingId = $list->id;
        $this->name = $list->name;
        $this->description = (string) $list->description;
        $this->dispatch('modal-open', name: 'address-list-form');
    }

    /**
     * A rename is written into the plan rows (Isp-Plan-Address-List) and customer rows (Mikrotik-Address-List)
     * that send the list, in the same transaction.
     */
    public function save(ReplaceRadiusValue $replace): void
    {
        $list = $this->editingId === null ? null : AddressList::query()->findOrFail($this->editingId);
        $this->authorize($list === null ? 'create' : 'update', $list ?? AddressList::class);

        $validated = $this->validate($this->rules($list?->id), [
            'name.regex' => __('Use letters, digits, _ and - only.'),
        ]);

        $attributes = ['name' => $validated['name'], 'description' => $validated['description'] ?: null];

        $renamed = DB::transaction(function () use ($list, $attributes, $replace): bool {
            if ($list === null) {
                AddressList::query()->create($attributes);

                return false;
            }

            $oldName = $list->name;
            $list->update($attributes);

            if (! $list->wasChanged('name')) {
                return false;
            }

            $replace->handle('radgroupreply', $list->plans()->pluck('slug'), 'Isp-Plan-Address-List', $oldName, $list->name);
            $replace->handle('radreply', $list->accounts()->pluck('username'), 'Mikrotik-Address-List', $oldName, $list->name);

            return true;
        }, attempts: 3);

        $this->dispatch('modal-close', name: 'address-list-form');
        $this->resetForm();
        $this->toast($renamed
            ? __('Address list renamed. Rename it in the router firewall rules too; online customers get the new name at their next login.')
            : __('Address list saved.'));
    }

    /**
     * Soft delete, refused while a plan or customer uses the list.
     */
    public function delete(int $listId): void
    {
        $list = AddressList::query()->withCount(['plans', 'accounts'])->findOrFail($listId);
        $this->authorize('delete', $list);

        if ($list->plans_count + $list->accounts_count > 0) {
            $this->toast(__('Used by :plans and :customers. Remove it from them first.', [
                'plans' => trans_choice(':count plan|:count plans', $list->plans_count),
                'customers' => trans_choice(':count customer|:count customers', $list->accounts_count),
            ]), 'danger');

            return;
        }

        $list->delete();

        $this->toast(__('Address list deleted.'));
    }

    public function restore(int $listId): void
    {
        $list = AddressList::onlyTrashed()->findOrFail($listId);
        $this->authorize('restore', $list);

        $list->restore();

        $this->toast(__('Address list restored.'));
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
            'name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/D',
                Rule::unique(AddressList::class, 'name')->withoutTrashed()->ignore($ignoreId),
                new NotUsedByTrashed(AddressList::class, 'name', __('A deleted address list uses this name. Restore it instead.'), $ignoreId),
            ],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
};
