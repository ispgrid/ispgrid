<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One provider's saved settings (API keys included, encrypted with APP_KEY) and whether it may send.
 */
#[Fillable(['provider', 'enabled', 'settings'])]
class NotificationProvider extends Model
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
     * @return list<string>
     */
    public static function enabledKeys(): array
    {
        return array_values(static::query()->where('enabled', true)->orderBy('provider')->get()
            ->map(fn (self $provider): string => $provider->provider)->all());
    }

    /**
     * @return array<string, string>
     */
    public static function settingsFor(string $key): array
    {
        return static::query()->where('provider', $key)->first()->settings ?? [];
    }
}
