<?php

namespace Tests\Fakes;

use App\Contracts\NeedsCustomerDetails;
use App\Contracts\PaymentGateway;
use App\Models\OnlinePayment;
use App\Payments\GatewayResult;
use App\Payments\GatewayStart;
use App\Payments\GatewayStatus;
use Illuminate\Http\Request;

/**
 * A test gateway that needs the customer's email.
 */
final class EmailGateway implements NeedsCustomerDetails, PaymentGateway
{
    /**
     * @param  array<string, string>  $settings
     */
    public function __construct(private array $settings) {}

    public static function key(): string
    {
        return 'emailgw';
    }

    public static function label(): string
    {
        return 'Email gateway';
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
        return ['email'];
    }

    public function start(OnlinePayment $payment, string $returnUrl): GatewayStart
    {
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
