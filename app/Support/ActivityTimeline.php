<?php

namespace App\Support;

use App\Enums\AccountStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * One customer activity entry as the Activity tab's timeline shows it: a plain sentence, a colour (tone) and an
 * icon by kind, who did it ("Name (email)" or System), the comment or remark, and the changed fields for a
 * details change or an event it has no sentence for. Events without a sentence (older ones, add-ons') read as
 * their ActivityFormatter label.
 */
final class ActivityTimeline
{
    /**
     * How the activity log stores dates (RechargeAccount::DATE_FORMAT).
     */
    private const STORED_DATE = 'Y-m-d H:i';

    /**
     * @param  string  $dateFormat  the app's date format, without a time
     * @return array{id: int, day: string, time: string, tone: string, icon: string, sentence: string, by: string, note: ?string, changes: list<array{field: string, old: ?string, new: ?string}>}
     */
    public static function entry(Activity $activity, string $dateFormat): array
    {
        $changes = collect($activity->attribute_changes ?? [])->toArray();
        $old = $changes['old'] ?? [];
        $new = $changes['attributes'] ?? [];
        $sentence = self::sentence($activity, $old, $new, $dateFormat);

        return [
            'id' => $activity->id,
            'day' => $activity->created_at->format($dateFormat),
            'time' => $activity->created_at->format('H:i'),
            'tone' => self::tone((string) $activity->event, $new),
            'icon' => self::icon((string) $activity->event),
            'sentence' => $sentence ?? ActivityFormatter::eventLabel($activity).'.',
            'by' => self::by($activity),
            'note' => self::text($new['note'] ?? $new['remark'] ?? $new['comment'] ?? $new['reason'] ?? null),
            'changes' => $sentence === null || $activity->event === 'updated' ? ActivityFormatter::changes($activity) : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private static function sentence(Activity $activity, array $old, array $new, string $dateFormat): ?string
    {
        $date = fn (mixed $value): string => self::date($value, $dateFormat);
        $money = fn (mixed $value): string => Money::fromSettings()->format((string) ($value ?? '0'));
        $from = fn (): string => self::periodStart($activity, $old['expiry'] ?? null, $dateFormat);
        $period = fn (): array => ['plan' => self::text($new['plan'] ?? null) ?? __('none'), 'from' => $from(), 'to' => $date($new['expiry'] ?? null), 'amount' => $money($new['amount'] ?? null)];

        $sentences = [
            'created' => fn (): string => __('Customer created.'),
            'recharged' => fn (): string => __('Recharged: :plan, :from to :to, :amount.', $period()),
            'plan-changed' => fn (): string => __('Plan changed from :old to :new, now to :to, :amount.', [
                'old' => self::text($old['plan'] ?? null) ?? __('none'), 'new' => self::text($new['plan'] ?? null) ?? __('none'),
                'to' => $date($new['expiry'] ?? null), 'amount' => $money($new['amount'] ?? null),
            ]),
            'advance-booked' => fn (): string => __('Advance renewal booked: :plan from :from, :amount.', [
                'plan' => self::text($new['plan'] ?? null) ?? __('none'), 'from' => $date($new['starts'] ?? null), 'amount' => $money($new['amount'] ?? null),
            ]),
            'advance-started' => fn (): string => __('Advance renewal started: :plan, :from to :to, :amount.', $period()),
            'advance-cancelled' => fn (): string => __('Advance renewal cancelled.'),
            'advance-moved' => fn (): string => __('Advance renewal moved to start :from.', ['from' => $date($new['starts'] ?? null)]),
            'auto-renewed' => fn (): string => __('Auto renewed: :plan, :from to :to, :amount.', $period()),
            'auto-renew-skipped' => fn (): string => __('Auto renew skipped: :reason.', ['reason' => self::text($new['reason'] ?? null) ?? '']),
            'expired' => fn (): string => __('Plan expired: :plan ended :expiry.', ['plan' => self::text($new['plan'] ?? null) ?? __('none'), 'expiry' => $date($new['expiry'] ?? null)]),
            'expiry-changed' => fn (): string => __('Expiry changed from :old to :new.', ['old' => $date($old['expiry'] ?? null), 'new' => $date($new['expiry'] ?? null)]),
            'own-speed-set' => fn (): string => ($new['until'] ?? null) === null
                ? __('Override speed set to :speed with no end date.', ['speed' => self::text($new['speed'] ?? null) ?? ''])
                : __('Override speed set to :speed until :until.', ['speed' => self::text($new['speed'] ?? null) ?? '', 'until' => $date($new['until'])]),
            'own-speed-removed' => fn (): string => __('Override speed removed.'),
            'own-speed-ended' => fn (): string => __('Override speed ended.'),
            'terminated' => fn (): string => __('Terminated.'),
            'status-changed' => fn (): string => __('Status changed from :old to :new.', ['old' => self::text($old['status'] ?? null) ?? '', 'new' => self::text($new['status'] ?? null) ?? '']),
            'updated' => fn (): string => __('Details changed.'),
        ];

        return isset($sentences[$activity->event]) ? $sentences[$activity->event]() : null;
    }

    /**
     * green: created or turned on; blue: plan and money; red: turned off, expired or failed; amber: changed;
     * grey: the rest.
     *
     * @param  array<string, mixed>  $new
     */
    private static function tone(string $event, array $new): string
    {
        return match ($event) {
            'created', 'restored' => 'green',
            'status-changed' => in_array($new['status'] ?? null, [AccountStatus::Active->label(), 'Active'], true) ? 'green' : 'red',
            'recharged', 'plan-changed', 'advance-booked', 'advance-started', 'advance-cancelled', 'advance-moved', 'auto-renewed' => 'blue',
            'terminated', 'expired', 'deleted', 'disconnect-failed', 'auto-renew-skipped' => 'red',
            'updated', 'expiry-changed', 'own-speed-set', 'own-speed-removed', 'own-speed-ended', 'password-changed' => 'amber',
            default => 'grey',
        };
    }

    /**
     * A Heroicons name (App\Support\Heroicons).
     */
    private static function icon(string $event): string
    {
        return match ($event) {
            'created', 'advance-booked', 'imported' => 'plus',
            'restored', 'status-changed' => 'check',
            'recharged', 'plan-changed', 'advance-started', 'auto-renewed', 'advance-moved' => 'arrow-path',
            'expired', 'auto-renew-skipped', 'disconnect-failed' => 'exclamation-triangle',
            'terminated', 'deleted', 'advance-cancelled' => 'no-symbol',
            'updated', 'expiry-changed', 'own-speed-set', 'own-speed-removed', 'own-speed-ended' => 'adjustments-horizontal',
            'password-changed', 'unlocked' => 'key',
            'disconnected' => 'x-circle',
            default => 'list-bullet',
        };
    }

    private static function by(Activity $activity): string
    {
        $causer = $activity->causer;

        return $causer instanceof User ? "{$causer->name} ({$causer->email})" : ActivityFormatter::causerName($activity);
    }

    /**
     * Where a recharge's period starts: the old expiry when it was still ahead (an early recharge, an advance or
     * auto renewal), otherwise when the entry was written.
     */
    private static function periodStart(Activity $activity, mixed $oldExpiry, string $dateFormat): string
    {
        $old = self::parse($oldExpiry);
        $start = $old !== null && $old->greaterThan($activity->created_at) ? $old : CarbonImmutable::instance($activity->created_at);

        return $start->format($dateFormat.' H:i');
    }

    /**
     * A stored date in the app's format; anything else (a validity waiting for the first login) as it is.
     */
    private static function date(mixed $value, string $dateFormat): string
    {
        $parsed = self::parse($value);

        return $parsed?->format($dateFormat.' H:i') ?? (self::text($value) ?? __('none'));
    }

    private static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!'.self::STORED_DATE, $value) ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
