<?php

namespace App\Actions\Customers;

use App\Actions\Radius\SyncAccountToRadius;
use App\Enums\AccountStatus;
use App\Enums\ServiceType;
use App\Models\Account;
use App\Support\AccountNumbers;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates one customer for an add-on (public add-on API, CONTRIBUTING "Write an add-on"): Inactive, no plan, with
 * its FreeRADIUS rows and the next account ID, and one "Created" entry in its Activity log. Sends no message.
 * A recharge (RechargeAccount) gives it a plan and makes it Active. $source names the add-on (such as
 * "hotspot-vouchers"); the customers list and the dashboard leave such customers out unless asked.
 */
final class CreateAccount
{
    /**
     * The columns an add-on may set.
     */
    private const COLUMNS = ['username', 'password', 'fullname', 'mobile', 'email', 'remarks', 'service_type', 'mac_type',
        'nas_id', 'connection_type_id', 'billing_free', 'notifications_off'];

    public function __construct(private SyncAccountToRadius $sync) {}

    /**
     * @param  array<string, mixed>  $columns  username, password, fullname and service_type (a ServiceType) are required;
     *                                         mobile, email, remarks, mac_type, nas_id, connection_type_id, billing_free and
     *                                         notifications_off may be given. An add-on passes them from its own code,
     *                                         so they are checked here.
     * @param  string  $source  lowercase letters, digits and "-", at most 100 characters
     *
     * @throws InvalidArgumentException for a column outside the list, a bad source, or a username or password that is
     *                                  empty or not English letters, digits and symbols
     * @throws UniqueConstraintViolationException when the username (or account ID) is taken
     */
    public function handle(array $columns, string $source): Account
    {
        $unknown = array_diff(array_keys($columns), self::COLUMNS);

        if ($unknown !== []) {
            throw new InvalidArgumentException('CreateAccount does not set '.implode(', ', $unknown).'.');
        }

        if (preg_match('/^[a-z0-9-]{1,100}$/D', $source) !== 1) {
            throw new InvalidArgumentException('The source must be lowercase letters, digits and "-".');
        }

        foreach (['username', 'password'] as $key) {
            if (! is_string($columns[$key] ?? null) || preg_match('/^[\x21-\x7E]{1,64}$/D', $columns[$key]) !== 1) {
                throw new InvalidArgumentException("The {$key} must be 1 to 64 English letters, digits or symbols.");
            }
        }

        if (! ($columns['service_type'] ?? null) instanceof ServiceType) {
            throw new InvalidArgumentException('The service type is required.');
        }

        if (! is_string($columns['fullname'] ?? null) || trim($columns['fullname']) === '') {
            throw new InvalidArgumentException('The name is required.');
        }

        return DB::transaction(function () use ($columns, $source): Account {
            $account = activity()->withoutLogging(fn (): Account => Account::query()->create([
                ...$columns,
                'source' => $source,
                'status_id' => AccountStatus::Inactive,
            ]));
            AccountNumbers::assign($account);
            $this->sync->handle($account);

            activity()
                ->performedOn($account)
                ->event('created')
                ->withChanges(['attributes' => self::loggedAttributes($account) + ['source' => $source]])
                ->log('created');

            return $account;
        }, attempts: 3);
    }

    /**
     * The columns the model logs, as the customer form logs a new customer.
     *
     * @return array<string, mixed>
     */
    private static function loggedAttributes(Account $account): array
    {
        $attributes = [];

        foreach ($account->getActivitylogOptions()->logAttributes as $field) {
            $value = $account->getAttribute($field);
            $attributes[$field] = match (true) {
                $value instanceof BackedEnum => $value->value,
                $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
                default => $value,
            };
        }

        return array_filter($attributes, fn (mixed $value): bool => $value !== null);
    }
}
