<?php

namespace App\Models;

use Database\Factories\PlanAttributeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A custom RADIUS reply attribute on a plan (radgroupreply).
 */
#[Fillable(['plan_id', 'attribute', 'op', 'value'])]
class PlanAttribute extends Model
{
    /** @use HasFactory<PlanAttributeFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
