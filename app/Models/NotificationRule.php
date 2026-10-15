<?php

namespace App\Models;

use Database\Factories\NotificationRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An operator's rule: on this trigger, for these customers, send this provider template with these values.
 *
 * @property array<string, mixed>|null $trigger_settings
 * @property array{plans?: list<int>, service_types?: list<string>, statuses?: list<int>}|null $filters
 * @property array<string, string>|null $template
 * @property list<string>|null $values
 */
#[Fillable(['name', 'trigger', 'trigger_settings', 'filters', 'provider', 'template', 'values', 'approved_text', 'enabled'])]
class NotificationRule extends Model
{
    /** @use HasFactory<NotificationRuleFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $attributes = ['enabled' => false];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'trigger', 'trigger_settings', 'filters', 'provider', 'template', 'values', 'approved_text', 'enabled'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger_settings' => 'array',
            'filters' => 'array',
            'template' => 'array',
            'values' => 'array',
            'enabled' => 'boolean',
        ];
    }

    /**
     * @return HasMany<NotificationLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }
}
