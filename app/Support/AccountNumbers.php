<?php

namespace App\Support;

use App\Models\Account;
use App\Models\AppSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Account IDs: the operator's prefix plus a number counted from their start number (App settings). Off until a start
 * number is saved. The full text is stored on the customer, so a new prefix applies to new customers only. The next
 * number is an app_settings row locked inside the save transaction, as invoice numbers lock theirs; a number any
 * customer already holds (typed by staff, or a deleted customer's) is skipped.
 */
final class AccountNumbers
{
    public const PREFIX = 'customers.account_id_prefix';

    public const START = 'customers.account_id_start';

    public const NEXT = 'customers.account_id_next';

    public const MAX_LENGTH = 30;

    public static function enabled(): bool
    {
        return trim((string) AppSetting::valueOf(self::START, '')) !== '';
    }

    public static function prefix(): string
    {
        return (string) AppSetting::valueOf(self::PREFIX, '');
    }

    /**
     * Gives the customer the next free account ID. Runs inside the caller's transaction; does nothing while account
     * IDs are off or the customer already has one. $skip holds IDs about to be given by hand (an import file's
     * account_id column), so a row further down does not find its ID taken.
     *
     * @param  list<string>  $skip
     */
    public static function assign(Account $account, array $skip = []): void
    {
        if ($account->account_number !== null || ! self::enabled()) {
            return;
        }

        $start = (int) AppSetting::valueOf(self::START, '1');
        // The counter is bookkeeping, not a setting staff changed: it stays out of the activity log.
        activity()->withoutLogging(fn () => AppSetting::query()->firstOrCreate(['key' => self::NEXT], ['value' => (string) $start]));
        $counter = AppSetting::query()->where('key', self::NEXT)->lockForUpdate()->firstOrFail();
        $counter->disableLogging();
        $prefix = self::prefix();
        $number = max(1, (int) $counter->value);

        $skip = array_flip(array_map('strtolower', $skip));

        while (isset($skip[strtolower($prefix.$number)]) || Account::withTrashed()->where('account_number', $prefix.$number)->exists()) {
            $number++;
        }

        $account->forceFill(['account_number' => $prefix.$number])->saveQuietly();
        $counter->update(['value' => (string) ($number + 1)]);
    }

    /**
     * The customers whose ID is the typed text, or the current prefix followed by it.
     *
     * @return Builder<Account>
     */
    public static function matching(string $typed): Builder
    {
        $typed = trim($typed);

        return Account::query()->where(fn (Builder $query) => $query
            ->where('account_number', $typed)
            ->when(self::prefix() !== '', fn (Builder $query) => $query->orWhere('account_number', self::prefix().$typed)));
    }

    /**
     * An account ID typed by staff or read from an import file.
     *
     * @return list<mixed>
     */
    public static function rules(?int $ignoreId = null): array
    {
        return ['nullable', 'string', 'max:'.self::MAX_LENGTH, 'regex:/^[A-Za-z0-9\/._-]+$/D',
            Rule::unique(Account::class, 'account_number')->ignore($ignoreId)];
    }
}
