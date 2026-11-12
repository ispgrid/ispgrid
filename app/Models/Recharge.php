<?php

namespace App\Models;

use App\Casts\Amount;
use App\Enums\AccountStatus;
use App\Enums\RechargeKind;
use Database\Factories\RechargeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recharge of an account: its kind, the plan, the expiry before and after (or the validity left waiting for
 * the first login), and who did it. Rows made since Cancel recharge existed also keep the old plan, first-login
 * validity and status, so the latest one can be undone; a cancelled row stays, marked, and drops out of totals.
 */
#[Fillable(['account_id', 'plan_id', 'old_plan_id', 'kind', 'old_expiry', 'new_expiry', 'new_pending_validity',
    'old_pending_validity', 'old_status', 'amount', 'invoice_id', 'note', 'user_id', 'cancelled_at', 'cancelled_by',
    'cancel_reason'])]
class Recharge extends Model
{
    /** @use HasFactory<RechargeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_expiry' => 'datetime',
            'new_expiry' => 'datetime',
            'new_pending_validity' => 'integer',
            'amount' => Amount::class,
            'kind' => RechargeKind::class,
            'old_pending_validity' => 'integer',
            'old_status' => AccountStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Recharges that count in sales and renewal figures: not cancelled and not a plan switch.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function counted(Builder $query): void
    {
        $query->whereNull('cancelled_at')->where('kind', '!=', RechargeKind::SwitchPlan->value);
    }

    /**
     * The plan the account had before this recharge.
     *
     * @return BelongsTo<Plan, $this>
     */
    public function oldPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'old_plan_id');
    }

    /**
     * The staff member who cancelled it.
     *
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * The staff member who recharged.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The invoice issued for it, if any.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
