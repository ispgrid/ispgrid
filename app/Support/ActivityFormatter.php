<?php

namespace App\Support;

use App\Models\Account;
use App\Models\AddressList;
use App\Models\AppSetting;
use App\Models\ConnectionType;
use App\Models\Ip;
use App\Models\IpRange;
use App\Models\Nas;
use App\Models\Plan;
use App\Models\Pool;
use App\Models\RadiusSetting;
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
        Account::class => 'Customer',
        User::class => 'Staff',
        AppSetting::class => 'App setting',
        ConnectionType::class => 'Connection type',
        Nas::class => 'NAS',
        Pool::class => 'Pool',
        IpRange::class => 'IP range',
        Ip::class => 'IP address',
        AddressList::class => 'Address list',
        Plan::class => 'Plan',
        RadiusSetting::class => 'FreeRADIUS settings',
    ];

    public static function subjectType(?string $class): string
    {
        return __(self::SUBJECT_TYPES[$class] ?? class_basename((string) $class));
    }

    public static function subjectName(Activity $activity): string
    {
        $subject = $activity->subject;

        return match (true) {
            $subject instanceof User, $subject instanceof ConnectionType,
            $subject instanceof Pool, $subject instanceof AddressList, $subject instanceof Plan => $subject->name,
            $subject instanceof Nas => $subject->shortname ?? $subject->nasname,
            $subject instanceof IpRange => $subject->name.' ('.$subject->network.')',
            $subject instanceof Ip => $subject->address,
            $subject instanceof RadiusSetting => __('FreeRADIUS settings'),
            $subject instanceof AppSetting => $subject->key,
            $subject instanceof Account => $subject->username,
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
            'status-changed' => __('Status changed'),
            'devices-reset' => __('Devices reset'),
            'unlocked' => __('Unlocked'),
            'password-changed' => __('Password changed'),
            'recharged' => __('Recharged'),
            'disconnected' => __('Disconnected'),
            'disconnect-failed' => __('Disconnect failed'),
            'imported' => __('Imported'),
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
