<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An attribute staff may add as a custom RADIUS reply attribute (seeded allowlist, read-only in the MVP).
 */
#[Fillable(['name', 'type', 'allowed_values', 'repeatable', 'description'])]
class RadiusAttribute extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allowed_values' => 'array',
            'repeatable' => 'boolean',
        ];
    }
}
