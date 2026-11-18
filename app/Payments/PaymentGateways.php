<?php

namespace App\Payments;

use App\Billing\BillingSettings;
use App\Contracts\NeedsCustomerDetails;
use App\Contracts\PaymentGateway;
use App\Models\Account;
use App\Models\GatewaySetting;
use App\Models\PaymentMethod;
use InvalidArgumentException;

/**
 * The payment gateways this install knows (a singleton). Paytm is registered in AppServiceProvider; an add-on
 * registers its own in its service provider with the same call.
 */
final class PaymentGateways
{
    /** @var array<string, class-string<PaymentGateway>> */
    private array $gateways = [];

    /**
     * @param  class-string<PaymentGateway>  $class
     */
    public function register(string $class): void
    {
        $this->gateways[$class::key()] = $class;
    }

    /**
     * @return array<string, class-string<PaymentGateway>>
     */
    public function all(): array
    {
        return $this->gateways;
    }

    /**
     * @return ?class-string<PaymentGateway>
     */
    public function class(string $key): ?string
    {
        return $this->gateways[$key] ?? null;
    }

    /**
     * Whether the gateway can charge in the Billing currency.
     */
    public function supportsCurrency(string $key): bool
    {
        $class = $this->class($key);

        return $class !== null && in_array(BillingSettings::current()->currencyCode, $class::currencies(), true);
    }

    /**
     * The gateways quick pay may use now: registered, turned on and able to charge in the Billing currency.
     *
     * @return list<string>
     */
    public function enabled(): array
    {
        $on = GatewaySetting::query()->where('enabled', true)->pluck('gateway')->all();

        return array_values(array_filter(array_keys($this->gateways), fn (string $key): bool => in_array($key, $on, true) && $this->supportsCurrency($key)));
    }

    /**
     * The enabled gateways this customer can use: those whose required details (mobile, email) they have.
     *
     * @return list<string>
     */
    public function enabledFor(Account $account): array
    {
        return $this->splitFor($account)['usable'];
    }

    /**
     * The enabled gateways hidden from this customer, with the reason to show them.
     *
     * @return array<string, string> gateway key => message
     */
    public function unavailableFor(Account $account): array
    {
        return $this->splitFor($account)['hidden'];
    }

    /**
     * Both lists at once, from one look at the enabled gateways and one reading of the customer's details.
     *
     * @return array{usable: list<string>, hidden: array<string, string>}
     */
    public function splitFor(Account $account): array
    {
        $details = CustomerDetails::for($account);
        $usable = [];
        $hidden = [];

        foreach ($this->enabled() as $key) {
            $missing = $this->missingDetail($key, $details);

            if ($missing === null) {
                $usable[] = $key;
            } else {
                $hidden[$key] = $missing === 'phone'
                    ? __('Mobile number needed for this payment option, contact us.')
                    : __('Email address needed for this payment option, contact us.');
            }
        }

        return ['usable' => $usable, 'hidden' => $hidden];
    }

    /**
     * @return 'phone'|'email'|null the first detail the gateway needs and the customer has not
     */
    private function missingDetail(string $key, CustomerDetails $details): ?string
    {
        $class = $this->class($key);

        if ($class === null || ! is_subclass_of($class, NeedsCustomerDetails::class)) {
            return null;
        }

        foreach ($class::requiredDetails() as $detail) {
            if (($detail === 'phone' ? $details->phone : $details->email) === null) {
                return $detail;
            }
        }

        return null;
    }

    /**
     * The gateway with its saved settings, for an attempt already made: turning a gateway off later must not strand
     * a payment the customer has already made, so this does not check that it is still on.
     */
    public function make(string $key): ?PaymentGateway
    {
        $class = $this->class($key);

        return $class === null ? null : new $class(GatewaySetting::settingsFor($key));
    }

    /**
     * The gateway's fixed payment method, "Online (label)", made the first time it is needed. Staff never pick it.
     */
    public function method(string $key): PaymentMethod
    {
        $label = ($this->class($key) ?? throw new InvalidArgumentException("Unknown payment gateway {$key}."))::label();

        return PaymentMethod::query()->firstOrCreate(['gateway' => $key], [
            'name' => PaymentMethod::query()->where('name', "Online ({$label})")->exists() ? "Online ({$label}, {$key})" : "Online ({$label})",
            'active' => true,
            'position' => (int) PaymentMethod::query()->max('position') + 10,
        ]);
    }

    /**
     * The saved values of a gateway's secret fields, for keeping them out of anything stored or logged.
     *
     * @param  array<string, string>  $settings
     * @return list<string>
     */
    public function secretValues(string $key, array $settings): array
    {
        $class = $this->class($key);
        $values = [];

        foreach ($class === null ? [] : $class::settingFields() as $field) {
            if ($field->isSecret() && ($settings[$field->name] ?? '') !== '') {
                $values[] = (string) $settings[$field->name];
            }
        }

        return $values;
    }
}
