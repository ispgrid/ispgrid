<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * One payment gateway's saved settings (merchant keys included, encrypted with APP_KEY) and whether quick pay may
 * use it.
 */
#[Table('payment_gateways')]
#[Fillable(['gateway', 'enabled', 'settings'])]
class GatewaySetting extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'settings' => 'encrypted:array',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function settingsFor(string $key): array
    {
        return static::query()->where('gateway', $key)->first()->settings ?? [];
    }
}
