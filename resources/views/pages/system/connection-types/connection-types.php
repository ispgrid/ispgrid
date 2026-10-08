<?php

use App\Livewire\Concerns\ShowsToasts;
use App\Models\ConnectionType;
use App\Rules\NotUsedByTrashed;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Connection types')] class extends Component
{
    use ShowsToasts;

    public string $name = '';

    public ?int $editingId = null;

    public string $editingName = '';

    public function mount(): void
    {
        $this->authorize('viewAny', ConnectionType::class);
    }

    /**
     * Live types first, then deleted ones, each with its customer count.
     *
     * @return Collection<int, ConnectionType>
     */
    #[Computed]
    public function types(): Collection
    {
        return ConnectionType::withTrashed()
            ->withCount('accounts')
            ->orderByRaw('deleted_at is not null')
            ->orderBy('name')
            ->get();
    }

    public function add(): void
    {
        $this->authorize('create', ConnectionType::class);

        ConnectionType::query()->create($this->validate(['name' => $this->nameRules()]));

        $this->reset('name');
        $this->toast(__('Connection type added.'));
    }

    public function edit(int $typeId): void
    {
        $type = ConnectionType::query()->findOrFail($typeId);

        $this->editingId = $type->id;
        $this->editingName = $type->name;
        $this->resetErrorBag();
    }

    public function rename(): void
    {
        $type = ConnectionType::query()->findOrFail($this->editingId);
        $this->authorize('update', $type);

        $this->validate(['editingName' => $this->nameRules($type->id)], attributes: ['editingName' => __('name')]);

        $type->update(['name' => $this->editingName]);

        $this->cancelEdit();
        $this->toast(__('Connection type renamed.'));
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'editingName');
        $this->resetErrorBag();
    }

    /**
     * Soft delete, refused while any customer still uses the type.
     */
    public function delete(int $typeId): void
    {
        $type = ConnectionType::query()->withCount('accounts')->findOrFail($typeId);
        $this->authorize('delete', $type);

        if ($type->accounts_count > 0) {
            $this->toast(trans_choice(
                'Used by :count customer. Move them to another type first.|Used by :count customers. Move them to another type first.',
                $type->accounts_count,
            ), 'danger');

            return;
        }

        $type->delete();

        $this->toast(__('Connection type deleted.'));
    }

    public function restore(int $typeId): void
    {
        $type = ConnectionType::onlyTrashed()->findOrFail($typeId);
        $this->authorize('restore', $type);

        $type->restore();

        $this->toast(__('Connection type restored.'));
    }

    /**
     * @return array<int, mixed>
     */
    private function nameRules(?int $ignoreId = null): array
    {
        return [
            'required',
            'string',
            'max:50',
            Rule::unique(ConnectionType::class, 'name')->withoutTrashed()->ignore($ignoreId),
            new NotUsedByTrashed(ConnectionType::class, 'name', __('A deleted connection type uses this name. Restore it instead.'), $ignoreId),
        ];
    }
};
