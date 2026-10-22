<?php

namespace App\Support;

use App\Enums\IpRangeKind;
use App\Enums\ServiceType;
use App\Models\Account;
use App\Models\ConnectionType;
use App\Models\Ip;
use App\Models\Pool;
use App\Rules\NotUsedByTrashed;
use Illuminate\Validation\Rule;

/**
 * The customer rules the customer form and the customer import share, so a row is accepted exactly when the
 * form would accept the same values.
 */
final class CustomerRules
{
    /**
     * The fields the operator made required (RequiredCustomerFields) start with "required" instead of "nullable".
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(bool $isNew, bool $isIpoe, bool $hasAddress, int $phoneDigits): array
    {
        $required = RequiredCustomerFields::current();
        $contact = [
            'company' => ['nullable', 'string', 'max:255'],
            'mobile' => self::phone($phoneDigits),
            'whatsapp' => self::phone($phoneDigits),
            'alt_number' => self::phone($phoneDigits),
            'email' => ['nullable', 'email', 'max:100'],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'business_id' => ['nullable', 'string', 'max:30'],
        ];

        foreach (array_intersect(array_keys($contact), $required) as $field) {
            $contact[$field][0] = 'required';
        }

        return [
            'fullname' => ['required', 'string', 'max:100'],
            ...$contact,
            'address.line1' => [Rule::requiredIf($hasAddress || in_array('address', $required, true)), 'nullable', 'string', 'max:255'],
            'address.line2' => ['nullable', 'string', 'max:255'],
            'address.area' => ['nullable', 'string', 'max:100'],
            'address.city' => ['nullable', 'string', 'max:100'],
            'address.state' => ['nullable', 'string', 'max:100'],
            'address.postal_code' => ['nullable', 'string', 'max:20'],
            'address.country' => ['nullable', 'string', 'max:60'],
            'service_type' => ['required', Rule::in(array_map(fn (ServiceType $type): string => $type->value, Hotspot::serviceTypes()))],
            'username' => $isNew ? ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._@-]+$/D',
                Rule::unique(Account::class, 'username')->withoutTrashed(),
                new NotUsedByTrashed(Account::class, 'username', __('A deleted customer uses this username. Restore it instead.')),
            ] : [],
            'password' => [$isIpoe ? 'nullable' : 'required', 'string', 'max:64', 'regex:/^[\x21-\x7E]+$/D', 'not_regex:/["\'`\\\\]/'],
            'mac_type' => $isIpoe ? [] : ['required', 'integer', 'between:0,100'],
            'connection_type_id' => ['nullable', 'integer', Rule::exists(ConnectionType::class, 'id')->withoutTrashed()],
            'ip_address' => [Rule::requiredIf($isIpoe), 'nullable', 'ipv4'],
            'pool_id' => $isIpoe ? [] : ['nullable', 'integer', Rule::exists(Pool::class, 'id')->withoutTrashed()],
        ];
    }

    /**
     * @return list<string>
     */
    public static function phone(int $digits): array
    {
        return ['nullable', 'regex:/^[0-9]{'.$digits.'}$/'];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(int $phoneDigits): array
    {
        $digits = __('Enter :digits digits.', ['digits' => $phoneDigits]);

        return [
            'mobile.regex' => $digits,
            'whatsapp.regex' => $digits,
            'alt_number.regex' => $digits,
            'service_type.in' => Hotspot::enabled()
                ? __('Choose PPPoE, Hotspot or IPoE.')
                : __('Choose PPPoE or IPoE. Hotspot is turned off in App settings.'),
            'address.line1.required' => in_array('address', RequiredCustomerFields::current(), true)
                ? __('Enter the first address line.')
                : __('Enter the first address line, or leave the whole address empty.'),
            'username.regex' => __('Use letters, digits, ., -, _ and @ only.'),
            'password.regex' => __('Use letters, digits and symbols, without spaces.'),
            'password.not_regex' => __('Leave out quotes, backticks and backslashes: FreeRADIUS reads them as part of its own syntax.'),
            'connection_type_id.exists' => __('This connection type was deleted. Choose another one.'),
            'pool_id.exists' => __('This pool was deleted. Choose another one.'),
            'ip_address.required' => __('IPoE customers are found by their IP address. Enter one.'),
            'ip_address.ipv4' => __('Enter an IPv4 address, e.g. 172.16.30.20.'),
        ];
    }

    /**
     * The address must be in the inventory (a live range, not taken out) and held by no other customer.
     */
    public static function ipError(string $address, ?int $accountId, bool $isIpoe = false): ?string
    {
        if ($address === '' || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        $ip = Ip::query()->with(['range', 'account'])->where('address', IpAddress::normalize($address))->first();

        if ($ip === null || $ip->range === null) {
            return __('This address is not in the IP inventory, or it was taken out. Add its range under Network, IP ranges.');
        }

        if ($isIpoe && $ip->range->kind !== IpRangeKind::Ipoe) {
            return __('IPoE customers need an address from an IPoE range.');
        }

        $holder = $ip->account;

        if ($holder !== null && $holder->id !== $accountId) {
            return __(':username already has this address.', ['username' => $holder->username]);
        }

        if ($holder === null && $ip->is_used) {
            return __('This address is given out. Pick another.');
        }

        return null;
    }
}
