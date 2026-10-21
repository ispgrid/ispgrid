<?php

namespace App\Contracts;

use App\Models\OnlinePayment;
use App\Notifications\SettingField;
use App\Payments\GatewayResult;
use App\Payments\GatewayStart;
use Illuminate\Http\Request;

/**
 * A payment gateway for quick pay (Paytm in core; add-ons register others the same way). A gateway only talks to its
 * provider: the core decides what is due, keeps the attempts, records payments and renewals and tells the customer.
 * Register it with app(PaymentGateways::class)->register(YourGateway::class). See CONTRIBUTING.md, "Add a payment
 * gateway".
 *
 * Optional abilities come as small extra interfaces a gateway may also implement, so this one never changes.
 */
interface PaymentGateway
{
    /**
     * @param  array<string, string>  $settings  the decrypted values of settingFields()
     */
    public function __construct(array $settings);

    /** Stored with every attempt: lowercase, never changed once released. */
    public static function key(): string;

    /** Shown to staff and on the pay page, and in the payment method name "Online (label)". */
    public static function label(): string;

    /** @return list<string> ISO 4217 codes it can charge in, e.g. ['INR'] */
    public static function currencies(): array;

    /**
     * Merchant ids and keys (mark keys 'secret'), test or live, and a host override: gateways move their domains, so
     * a host is a setting, never only a constant.
     *
     * @return list<SettingField>
     */
    public static function settingFields(): array;

    /**
     * Opens the payment at the provider and says where to send the customer. $returnUrl is where the provider sends
     * them back (and may post its result to). Throws GatewayUnavailable when the provider refuses.
     */
    public function start(OnlinePayment $payment, string $returnUrl): GatewayStart;

    /** Asks the provider, server to server, how the payment stands. The only proof a payment was made. */
    public function status(OnlinePayment $payment): GatewayResult;

    /**
     * Checks a return or webhook request really comes from the provider and names the attempt it is about.
     *
     * @return ?string the attempt's public_id, or null when the request is not genuine
     */
    public function verify(Request $request): ?string;
}
