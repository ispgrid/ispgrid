<?php

namespace App\Models;

use Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A way customers pay (Cash, UPI, ...), editable on the Billing settings page. One that has payments is turned
 * off instead of deleted. A method with a gateway is a quick pay gateway's fixed "Online (...)" method: staff
 * never pick, edit or delete it.
 */
#[Fillable(['name', 'active', 'position', 'gateway'])]
class PaymentMethod extends Model
{
    /** @use HasFactory<PaymentMethodFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * The methods staff work with: every one but the gateways' own.
     *
     * @param  Builder<PaymentMethod>  $query
     */
    #[Scope]
    protected function forStaff(Builder $query): void
    {
        $query->whereNull('gateway');
    }

    /**
     * Every payment made this way, removed ones too.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->withTrashed();
    }
}
