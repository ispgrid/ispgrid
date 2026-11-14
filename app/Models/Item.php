<?php

namespace App\Models;

use App\Casts\Amount;
use App\Enums\ItemSoldAs;
use App\Enums\ItemType;
use App\Enums\ServiceKind;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Something the operator sells: goods or a service, with a price (per month for a service), an optional HSN/SAC
 * code (India GST only) and a description. Sold once on an invoice line, or as a customer service with its own
 * paid-until date. An item that was ever used is made inactive, never deleted.
 */
#[Fillable(['name', 'type', 'unit', 'item_code', 'taxable', 'price', 'description', 'sold_as', 'service_kind', 'active'])]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ItemType::class,
            'sold_as' => ItemSoldAs::class,
            'service_kind' => ServiceKind::class,
            'taxable' => 'boolean',
            'active' => 'boolean',
            'price' => Amount::class,
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('active', true);
    }

    /**
     * Items sold once on an invoice line.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function oneTime(Builder $query): void
    {
        $query->where('sold_as', ItemSoldAs::OneTime->value);
    }

    /**
     * Items sold as a customer service.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function services(Builder $query): void
    {
        $query->where('sold_as', ItemSoldAs::Service->value);
    }

    /**
     * Whether an invoice line or a customer service points at this item, so it may only be made inactive.
     */
    public function isUsed(): bool
    {
        return InvoiceLine::query()->where('item_id', $this->id)->exists();
    }
}
