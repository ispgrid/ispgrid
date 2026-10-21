<?php

namespace App\Actions\Import;

use App\Actions\Radius\SyncAccountToRadius;
use App\Enums\AccountStatus;
use App\Enums\ServiceType;
use App\Exceptions\ImportFailed;
use App\Models\Account;
use App\Models\Address;
use App\Models\AppSetting;
use App\Models\ConnectionType;
use App\Models\Ip;
use App\Models\Plan;
use App\Models\Pool;
use App\Models\UsagePeriod;
use App\Support\CustomerDefaults;
use App\Support\CustomerRules;
use App\Support\IpAddress;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Customers from the customers template (Plan 9 spec): the customer form's rules, plus plan, expiry, status and
 * a bound MAC from the file. Written through SyncAccountToRadius; nobody is disconnected, the accounts are new.
 */
final class ImportCustomers implements Importer
{
    private const ADDRESS = ['line1', 'line2', 'area', 'city', 'state', 'postal_code', 'country'];

    /**
     * @var array<string, AccountStatus>
     */
    private const STATUSES = ['active' => AccountStatus::Active, 'inactive' => AccountStatus::Inactive, 'suspended' => AccountStatus::Suspended];

    /**
     * Customer form field => CSV column, where they differ (address.* fields drop their prefix).
     *
     * @var array<string, string>
     */
    private const COLUMN_OF = ['mac_type' => 'device_limit', 'ip_address' => 'ip', 'pool_id' => 'pool', 'connection_type_id' => 'connection_type'];

    public function __construct(private SyncAccountToRadius $sync) {}

    public function columns(): array
    {
        return array_keys($this->example());
    }

    public function requiredColumns(): array
    {
        return ['username', 'service_type', 'fullname'];
    }

    public function example(): array
    {
        return [
            'username' => 'alice', 'password' => 'secret12', 'service_type' => 'PPP', 'fullname' => 'Alice Rao',
            'mobile' => '9876543210', 'email' => 'alice@example.com', 'plan_slug' => 'home_25',
            'expiry' => '2026-11-30 23:59', 'status' => 'Active', 'device_limit' => '1', 'ip' => '', 'mac' => '',
            'pool' => '', 'connection_type' => 'Home', 'line1' => '12 Park Street', 'line2' => '', 'area' => 'Salt Lake',
            'city' => 'Kolkata', 'state' => 'West Bengal', 'postal_code' => '700091', 'country' => 'India',
        ];
    }

    public function check(array $rows): array
    {
        return array_merge(...array_column($this->prepare($rows), 'errors'));
    }

    public function import(array $rows): int
    {
        try {
            return DB::transaction(function () use ($rows): int {
                $prepared = $this->prepare($rows);
                $errors = array_merge(...array_column($prepared, 'errors'));

                if ($errors !== []) {
                    throw new ImportFailed($errors);
                }

                foreach ($prepared as $row) {
                    $this->write($row);
                }

                return count($prepared);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw new ImportFailed([new RowError(null, null, __('A record in this file was taken meanwhile by someone else. Check the file again.'))]);
        }
    }

    /**
     * @param  array{errors: list<RowError>, columns: array<string, mixed>, address: ?array<string, ?string>, mac: ?string}  $row
     */
    private function write(array $row): void
    {
        $columns = $row['columns'];
        $columns['address_id'] = $row['address'] === null ? null : Address::query()->create($row['address'])->id;

        // One "Imported" entry below instead of the model's own "Created" one.
        $account = activity()->withoutLogging(fn (): Account => Account::query()->create($columns));

        if ($account->usage_since !== null) {
            UsagePeriod::restart($account->username, $account->usage_since);
        }
        $this->sync->handle($account);

        // A device bound before the first login (app-integration.md section 6): FreeRADIUS reads it as bound.
        if ($row['mac'] !== null) {
            DB::table('account_macs')->insert(['username' => $account->username, 'mac_norm' => $row['mac'], 'learned_at' => now()]);
        }

        $this->logImported($account, $row['address']);
    }

    /**
     * Each row's problems and, when it has none, what to write.
     *
     * @param  list<array{line: int, values: array<string, string>}>  $rows
     * @return list<array{errors: list<RowError>, columns: array<string, mixed>, address: ?array<string, ?string>, mac: ?string}>
     */
    private function prepare(array $rows): array
    {
        $digits = (int) AppSetting::valueOf('phone_digits', '10');
        $plans = Plan::query()->get(['id', 'slug'])->keyBy(fn (Plan $plan): string => strtolower($plan->slug));
        $pools = Pool::query()->get(['id', 'name'])->keyBy(fn (Pool $pool): string => strtolower($pool->name));
        $types = ConnectionType::query()->get(['id', 'name'])->keyBy(fn (ConnectionType $type): string => strtolower($type->name));
        $seen = ['username' => [], 'ip' => [], 'mac' => []];
        $prepared = [];

        foreach ($rows as ['line' => $line, 'values' => $values]) {
            $prepared[] = $this->prepareRow($line, $values, $digits, $plans, $pools, $types, $seen);
        }

        return $prepared;
    }

    /**
     * @param  array<string, string>  $values
     * @param  Collection<string, Plan>  $plans
     * @param  Collection<string, Pool>  $pools
     * @param  Collection<string, ConnectionType>  $types
     * @param  array<string, array<string, int>>  $seen  the first row of each username, IP and MAC so far
     * @return array{errors: list<RowError>, columns: array<string, mixed>, address: ?array<string, ?string>, mac: ?string}
     */
    private function prepareRow(int $line, array $values, int $digits, Collection $plans, Collection $pools, Collection $types, array &$seen): array
    {
        $errors = [];
        $type = ServiceType::tryFrom(strtoupper($values['service_type']));
        $isIpoe = $type === ServiceType::Ipoe;
        $address = array_map(fn (string $value): ?string => $value === '' ? null : $value, Arr::only($values, self::ADDRESS));
        $hasAddress = array_filter($address, fn (?string $value): bool => $value !== null) !== [];

        foreach ($isIpoe ? ['username', 'mobile'] : ['username', 'password', 'mobile'] as $column) {
            // Excel shows a long number such as 123456789012 as 1.23457E+11 and saves it that way.
            if (preg_match('/^\d+(\.\d+)?E\+\d+$/i', $values[$column]) === 1) {
                $errors[$column] = __('Excel turned this into a number. Format the column as Text in Excel and type it again.');
            }
        }

        $pool = $values['pool'] === '' ? null : $pools->get(strtolower($values['pool']));

        if ($values['pool'] !== '' && $isIpoe) {
            $errors['pool'] = __('IPoE customers get no pool: their device keeps its own address.');
        } elseif ($values['pool'] !== '' && $pool === null) {
            $errors['pool'] = __('No pool named :name.', ['name' => $values['pool']]);
        }

        $connectionType = $values['connection_type'] === '' ? null : $types->get(strtolower($values['connection_type']));

        if ($values['connection_type'] !== '' && $connectionType === null) {
            $errors['connection_type'] = __('No connection type named :name.', ['name' => $values['connection_type']]);
        }

        $data = [
            'fullname' => $values['fullname'],
            'mobile' => $values['mobile'],
            'email' => $values['email'],
            'address' => $address,
            'service_type' => strtoupper($values['service_type']),
            'username' => $values['username'],
            'password' => $isIpoe ? '' : $values['password'],
            'mac_type' => $values['device_limit'] === '' ? '1' : $values['device_limit'],
            'connection_type_id' => $connectionType?->id,
            'ip_address' => $values['ip'],
            'pool_id' => $isIpoe ? null : $pool?->id,
        ];
        $rules = CustomerRules::rules(true, $isIpoe, $hasAddress, $digits);

        foreach (Validator::make($data, $rules, CustomerRules::messages($digits))->errors()->messages() as $field => $messages) {
            $column = str_starts_with($field, 'address.') ? substr($field, 8) : (self::COLUMN_OF[$field] ?? $field);
            $errors[$column] ??= $messages[0];
        }

        $ip = IpAddress::normalize($values['ip']) ?? '';
        $ipError = isset($errors['ip']) ? null : CustomerRules::ipError($ip, null, $isIpoe);

        if ($ipError !== null) {
            $errors['ip'] = $ipError;
        }

        $plan = $values['plan_slug'] === '' ? null : $plans->get(strtolower($values['plan_slug']));
        $expiry = $this->expiry($values['expiry']);
        $status = $values['status'] === '' ? AccountStatus::Inactive : (self::STATUSES[strtolower($values['status'])] ?? null);
        $errors = [...$errors, ...$this->planErrors($values, $plan, $expiry, $status)];

        $mac = strtoupper(str_replace([':', '-', '.'], '', $values['mac']));

        if ($values['mac'] !== '' && preg_match('/^[0-9A-F]{12}$/', $mac) !== 1) {
            $errors['mac'] = __('Enter a MAC address, e.g. AA:BB:CC:DD:EE:FF.');
        } elseif ($values['mac'] !== '') {
            $owner = DB::table('account_macs')->where('mac_norm', $mac)->value('username');

            if ($owner !== null) {
                $errors['mac'] = __(':username already uses this device.', ['username' => $owner]);
            }
        }

        $keys = ['username' => strtolower($values['username']), 'ip' => $ip, 'mac' => isset($errors['mac']) ? '' : ($values['mac'] === '' ? '' : $mac)];

        foreach ($keys as $column => $key) {
            if ($key === '') {
                continue;
            }

            if (isset($seen[$column][$key])) {
                $errors[$column] ??= match ($column) {
                    'username' => __('Row :row already uses this username.', ['row' => $seen[$column][$key]]),
                    'ip' => __('Row :row already uses this IP address.', ['row' => $seen[$column][$key]]),
                    default => __('Row :row already uses this MAC address.', ['row' => $seen[$column][$key]]),
                };
            }

            $seen[$column][$key] ??= $line;
        }

        return [
            'errors' => array_map(
                fn (string $column, string $message): RowError => new RowError($line, $column, $message),
                array_keys($errors),
                array_values($errors),
            ),
            'columns' => [
                'username' => $values['username'],
                'password' => $isIpoe ? Str::random(16) : $values['password'],
                'fullname' => $values['fullname'],
                'mobile' => $values['mobile'] === '' ? null : $values['mobile'],
                'email' => $values['email'] === '' ? null : $values['email'],
                'service_type' => $type,
                'mac_type' => $isIpoe ? 1 : (int) $data['mac_type'],
                'plan_id' => $plan?->id,
                'expiry' => $expiry,
                'usage_since' => $plan === null ? null : now(),
                'status_id' => $status ?? AccountStatus::Inactive,
                'ip_id' => $ip === '' ? null : Ip::query()->where('address', $ip)->value('id'),
                'pool_id' => $isIpoe || $type === null ? null : ($pool->id ?? CustomerDefaults::poolId($type)),
                'connection_type_id' => $connectionType?->id,
            ],
            'address' => $hasAddress ? $address : null,
            'mac' => $values['mac'] === '' ? null : $mac,
        ];
    }

    /**
     * A plan needs an expiry and an expiry needs a plan; Active needs both. These replace earlier errors of the
     * same columns.
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private function planErrors(array $values, ?Plan $plan, ?CarbonImmutable $expiry, ?AccountStatus $status): array
    {
        $errors = [];

        if ($values['plan_slug'] !== '' && $plan === null) {
            $errors['plan_slug'] = __('No plan with this slug.');
        }

        if ($values['expiry'] !== '' && $expiry === null) {
            $errors['expiry'] = __('Use YYYY-MM-DD HH:MM, e.g. 2026-11-30 23:59.');
        } elseif ($values['expiry'] !== '' && $values['plan_slug'] === '') {
            $errors['expiry'] = __('Give a plan with the expiry.');
        } elseif ($values['plan_slug'] !== '' && $values['expiry'] === '') {
            $errors['expiry'] = __('Give the expiry for this plan.');
        }

        if ($status === null) {
            $errors['status'] = __('Use Active, Inactive or Suspended.');
        } elseif ($status === AccountStatus::Active && ($values['plan_slug'] === '' || $values['expiry'] === '')) {
            $errors['status'] = __('An Active customer needs a plan and an expiry.');
        }

        return $errors;
    }

    /**
     * "YYYY-MM-DD HH:MM" or "YYYY-MM-DD" (midnight). A date that does not exist (2026-02-30) is refused, not
     * rolled over.
     */
    private function expiry(string $value): ?CarbonImmutable
    {
        foreach (['Y-m-d H:i', 'Y-m-d'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $value);
            } catch (InvalidFormatException) {
                continue;
            }

            if ($date instanceof CarbonImmutable && $date->format($format) === $value) {
                return $date;
            }
        }

        return null;
    }

    /**
     * One "Imported" entry with the customer's logged columns (never the password) and their address, like the
     * form's "Created" entry.
     *
     * @param  ?array<string, ?string>  $address
     */
    private function logImported(Account $account, ?array $address): void
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

        if ($address !== null) {
            $attributes['address'] = implode(', ', array_filter($address, fn (?string $value): bool => $value !== null));
        }

        activity()->performedOn($account)->event('imported')->withChanges(['attributes' => $attributes])->log('imported');
    }
}
