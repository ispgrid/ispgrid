<?php

namespace App\Payments;

use App\Billing\BillingSettings;
use App\Contracts\PaymentGateway;
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
