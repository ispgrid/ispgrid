<?php

use App\Billing\BillingSettings;
use App\Enums\ItemSoldAs;
use App\Enums\ItemType;
use App\Enums\ServiceKind;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\CustomerService;
use App\Models\Item;
use App\Support\Currency;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Items')] class extends Component
{
    use AuthorizesEveryRequest, ShowsToasts;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = 'active';

    public ?int $editingId = null;

    public string $name = '';

    public string $type = 'goods';

    public string $unit = '';

    public string $item_code = '';

    public bool $taxable = true;

    public string $price = '';

    public string $description = '';

    public string $sold_as = 'one_time';

    public string $service_kind = '';

    public bool $active = true;

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    /**
     * Who may use this page: checked when it opens and on every later request (AuthorizesEveryRequest).
     */
    protected function authorizeAccess(): void
    {
        $this->authorize('manage-items');
    }

    /**
     * @return Collection<int, Item>
     */
    #[Computed]
    public function items(): Collection
    {
        $search = trim($this->search);

        return Item::query()
            ->when($this->status === 'active', fn ($query) => $query->where('active', true))
            ->when($this->status === 'inactive', fn ($query) => $query->where('active', false))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', '%'.$search.'%')->orWhere('item_code', 'like', '%'.$search.'%')))
            ->orderBy('name')
            ->get();
    }

    /**
     * The HSN/SAC label under India GST; null for every other tax setup, which hides the code.
     */
    #[Computed]
    public function itemCodeLabel(): ?string
    {
        return BillingSettings::current()->tax()->itemCodeLabel();
    }

    #[Computed]
    public function money(): Money
    {
        return Money::fromSettings();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function statusOptions(): array
    {
        return ['active' => __('Active'), 'inactive' => __('Inactive'), 'all' => __('All')];
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function typeOptions(): array
    {
        return collect(ItemType::cases())->mapWithKeys(fn (ItemType $type): array => [$type->value => $type->label()])->all();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function soldAsOptions(): array
    {
        return collect(ItemSoldAs::cases())->mapWithKeys(fn (ItemSoldAs $soldAs): array => [$soldAs->value => $soldAs->label()])->all();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function serviceKindOptions(): array
    {
        return collect(ServiceKind::cases())->mapWithKeys(fn (ServiceKind $kind): array => [$kind->value => $kind->label()])->all();
    }

    public function openNew(): void
    {
        $this->authorize('manage-items');

        $this->resetForm();
        $this->dispatch('modal-open', name: 'item-form');
    }

    public function openEdit(int $id): void
    {
        $this->authorize('manage-items');
        $item = Item::query()->findOrFail($id);

        $this->resetForm();
        $this->editingId = $item->id;
        $this->name = $item->name;
        $this->type = $item->type->value;
        $this->unit = (string) $item->unit;
        $this->item_code = (string) $item->item_code;
        $this->taxable = $item->taxable;
        $this->price = (string) $item->price;
        $this->description = (string) $item->description;
        $this->sold_as = $item->sold_as->value;
        $this->service_kind = (string) $item->service_kind?->value;
        $this->active = $item->active;
        $this->dispatch('modal-open', name: 'item-form');
    }

    /**
     * Saves the item. The item code is only kept under India GST; a code saved earlier stays when the operator has
     * since switched setup, so it comes back if they switch again.
     */
    public function save(): void
    {
        $this->authorize('manage-items');
        $item = $this->editingId === null ? null : Item::query()->findOrFail($this->editingId);

        $validated = $this->validate($this->rules($item?->id), [
            'name.unique' => __('An active item already has this name.'),
            'sold_as.in' => __('Only a service can be sold as a service.'),
            'price.regex' => __('Enter an amount like 999 or 999.50.'),
        ]);

        if ($item !== null && $this->usedByServices($item)) {
            foreach (['type' => $item->type->value, 'sold_as' => $item->sold_as->value, 'service_kind' => (string) $item->service_kind?->value] as $field => $stored) {
                if ((string) $validated[$field] !== $stored) {
                    $this->addError($field, __('Customer services use this item, so this cannot change. Add a new item instead.'));
                }
            }

            if ($this->getErrorBag()->isNotEmpty()) {
                return;
            }
        }

        $soldAs = ItemSoldAs::from($validated['sold_as']);
        $code = $this->itemCodeLabel === null ? $item?->item_code : ($validated['item_code'] ?: null);

        $attributes = [
            'name' => trim($validated['name']),
            'type' => $validated['type'],
            'unit' => $validated['unit'] ?: null,
            'item_code' => $code,
            'taxable' => $validated['taxable'],
            'price' => $validated['price'],
            'description' => $validated['description'] ?: null,
            'sold_as' => $soldAs,
            'service_kind' => $soldAs === ItemSoldAs::Service ? $validated['service_kind'] : null,
            'active' => $validated['active'],
        ];

        try {
            $item === null ? Item::query()->create($attributes) : $item->update($attributes);
        } catch (UniqueConstraintViolationException) {
            $this->addError('name', __('An active item already has this name.'));

            return;
        }

        $this->dispatch('modal-close', name: 'item-form');
        $this->resetForm();
        $this->toast(__('Item saved.'));
    }

    /**
     * Deletes an item that was never used; a used item stays on its invoices and services and can only be made
     * inactive.
     */
    public function delete(int $id): void
    {
        $this->authorize('manage-items');
        $item = Item::query()->findOrFail($id);

        if ($item->isUsed()) {
            $this->toast(__('This item is in use. Make it inactive instead.'), 'danger');

            return;
        }

        $item->delete();

        $this->toast(__('Item deleted.'));
    }

    /**
     * Whether customer services were sold from this item: its kind decides how FreeRADIUS treats their IPs, so it
     * stays as it is.
     */
    private function usedByServices(Item $item): bool
    {
        return CustomerService::query()->where('item_id', $item->id)->exists();
    }

    /**
     * Whether the item open in the dialog has customer services: its type, sold as and kind are shown read-only.
     */
    #[Computed]
    public function kindLocked(): bool
    {
        return $this->editingId !== null && CustomerService::query()->where('item_id', $this->editingId)->exists();
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'type', 'unit', 'item_code', 'taxable', 'price', 'description', 'sold_as', 'service_kind', 'active');
        $this->resetErrorBag();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(?int $ignoreId): array
    {
        return [
            'name' => ['required', 'string', 'max:100',
                ...($this->active ? [Rule::unique(Item::class, 'name')->where('active', true)->ignore($ignoreId)] : [])],
            'type' => ['required', new Enum(ItemType::class)],
            'unit' => ['nullable', 'string', 'max:20'],
            'item_code' => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Za-z]*$/D'],
            'taxable' => ['boolean'],
            'price' => ['required', 'string', 'regex:'.Currency::amountPattern()],
            'description' => ['nullable', 'string', 'max:255'],
            'sold_as' => ['required', new Enum(ItemSoldAs::class), $this->type === ItemType::Service->value ? 'string' : Rule::in([ItemSoldAs::OneTime->value])],
            'service_kind' => ['nullable', 'required_if:sold_as,'.ItemSoldAs::Service->value, new Enum(ServiceKind::class)],
            'active' => ['boolean'],
        ];
    }
};
