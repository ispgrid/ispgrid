<?php

namespace App\Enums;

use Illuminate\Database\Eloquent\Builder;

/**
 * The outcome of a login attempt in radpostauth.
 */
enum LoginResult: string
{
    case Accepted = 'accepted';
    case DisabledPool = 'disabled-pool';
    case Rejected = 'rejected';
    case Dropped = 'dropped';

    /**
     * The reasons isp gives an account it accepts into the disabled pool (Isp-Disabled-Reason, and the limit reasons
     * without a fair usage speed). A throttled request is logged as "Throttled after: <reject reason>".
     */
    public const DISABLED_POOL_REASONS = [
        'Account is suspended',
        'Account is inactive',
        'Account expired',
        'No plan assigned',
        'Data limit reached',
        'Time limit reached',
        'Access is allowed only during the time slot',
    ];

    public const THROTTLED_PREFIX = 'Throttled after: ';

    /**
     * isp logs a request it dropped (no answer sent, Blast-RADIUS checks) with reply '0'. An accept into the disabled
     * pool is an Access-Accept whose reason says why.
     */
    public static function fromReply(string $reply, ?string $reason = null): self
    {
        return match ($reply) {
            'Access-Accept' => self::isDisabledPoolReason((string) $reason) ? self::DisabledPool : self::Accepted,
            '0' => self::Dropped,
            default => self::Rejected,
        };
    }

    public static function isDisabledPoolReason(string $reason): bool
    {
        return in_array($reason, self::DISABLED_POOL_REASONS, true) || str_starts_with($reason, self::THROTTLED_PREFIX);
    }

    /**
     * Limits a radpostauth query to the attempts with this result.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scope(Builder $query): Builder
    {
        $disabledPool = fn (Builder $query) => $query->whereIn('reply_msg', self::DISABLED_POOL_REASONS)
            ->orWhere('reply_msg', 'like', self::THROTTLED_PREFIX.'%');

        return match ($this) {
            self::Accepted => $query->where('reply', 'Access-Accept')
                ->where(fn (Builder $query) => $query->whereNull('reply_msg')->orWhereNot($disabledPool)),
            self::DisabledPool => $query->where('reply', 'Access-Accept')->where($disabledPool),
            self::Rejected => $query->where('reply', 'Access-Reject'),
            self::Dropped => $query->where('reply', '0'),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Accepted => __('Accepted'),
            self::DisabledPool => __('Disabled pool'),
            self::Rejected => __('Rejected'),
            self::Dropped => __('Dropped'),
        };
    }

    /**
     * The x-ui.badge colour for this result.
     */
    public function color(): string
    {
        return match ($this) {
            self::Accepted => 'green',
            self::DisabledPool => 'zinc',
            self::Rejected => 'red',
            self::Dropped => 'amber',
        };
    }
}
