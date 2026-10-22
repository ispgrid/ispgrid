<?php

namespace App\Models;

use App\Support\Currency;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * App settings that vary by country or ISP (currency, date format, labels, notice text, ...).
 */
#[Fillable(['key', 'value'])]
class AppSetting extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['key', 'value'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    /**
     * A saved currency_decimals takes effect at once: the request or job read the old value only once.
     */
    protected static function booted(): void
    {
        static::saved(function (AppSetting $setting): void {
            if ($setting->key === 'currency_decimals') {
                app()->forgetInstance(Currency::class);
            }
        });
    }

    public static function valueOf(string $key, ?string $default = null): ?string
    {
        return static::query()->where('key', $key)->value('value') ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
