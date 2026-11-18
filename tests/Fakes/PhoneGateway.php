<?php

namespace Tests\Fakes;

use App\Contracts\NeedsCustomerDetails;
use App\Contracts\PaymentGateway;
use App\Models\OnlinePayment;
use App\Payments\CustomerDetails;
use App\Payments\GatewayResult;
use App\Payments\GatewayStart;
use App\Payments\GatewayStatus;
use Illuminate\Http\Request;

/**
 * A test gateway that needs the customer's phone.
 */
final class PhoneGateway implements NeedsCustomerDetails, PaymentGateway
{
    /** @var ?CustomerDetails what start() was given */
    public static ?CustomerDetails $seen = null;

    /**
     * @param  array<string, string>  $settings
     */
    public function __construct(private array $settings) {}

    public static function key(): string
    {
        return 'phonegw';
    }

    public static function label(): string
    {
        return 'Phone gateway';
    }

    public static function currencies(): array
    {
        return ['INR'];
    }

    public static function settingFields(): array
    {
        return [];
    }

    public static function requiredDetails(): array
    {
        return ['phone'];
    }

    public function start(OnlinePayment $payment, string $returnUrl): GatewayStart
    {
        self::$seen = CustomerDetails::for($payment->account);

        return GatewayStart::redirect('https://gateway.test/pay');
    }

    public function status(OnlinePayment $payment): GatewayResult
    {
        return new GatewayResult(GatewayStatus::Pending);
    }

    public function verify(Request $request): ?string
    {
        return null;
    }
}
