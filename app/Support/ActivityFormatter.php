<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Models\ConnectionType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

/**
 * Turns activity-log rows into what the activity screen shows. Later plans add their models here.
 */
final class ActivityFormatter
{
    /**
     * @var array<class-string<Model>, string>
     */
    public const SUBJECT_TYPES = [
        User::class => 'Staff',
        AppSetting::class => 'App setting',
        ConnectionType::class => 'Connection type',
    ];

    public static function subjectType(?string $class): string
    {
        return __(self::SUBJECT_TYPES[$class] ?? class_basename((string) $class));
    }

    public static function subjectName(Activity $activity): string
    {
        $subject = $activity->subject;

        return match (true) {
            $subject instanceof User, $subject instanceof ConnectionType => $subject->name,
            $subject instanceof AppSetting => $subject->key,
            default => '#'.$activity->subject_id,
        };
    }

    public static function causerName(Activity $activity): string
    {
        return $activity->causer?->name ?? __('System');
    }

    public static function eventLabel(Activity $activity): string
    {
        return match ($activity->event) {
            'created' => __('Created'),
            'updated' => __('Updated'),
            'deleted' => $activity->subject_type === (new User)->getMorphClass() ? __('Disabled') : __('Deleted'),
            'restored' => __('Restored'),
            default => (string) $activity->event,
        };
    }

    /**
     * A delete records only the old values: show them as what was removed, not as a change to empty.
     *
     * @return list<array{field: string, old: ?string, new: ?string}>
     */
    public static function changes(Activity $activity): array
    {
        $changes = collect($activity->attribute_changes ?? [])->toArray();
        $new = $changes['attributes'] ?? [];
        $old = $changes['old'] ?? [];

        if ($activity->event === 'deleted' && $new === []) {
            [$new, $old] = [$old, []];
        }

        $rows = [];

        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $field) {
            $rows[] = [
                'field' => (string) $field,
                'old' => self::stringify($old[$field] ?? null),
                'new' => self::stringify($new[$field] ?? null),
            ];
        }

        return $rows;
    }

    private static function stringify(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'yes' : 'no',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }
}
