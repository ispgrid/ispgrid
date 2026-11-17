<?php

namespace Tests\Fakes;

use App\Contracts\PaymentGateway;
use App\Models\OnlinePayment;
use App\Payments\GatewayResult;
use App\Payments\GatewayStart;
use App\Payments\GatewayStatus;
use Illuminate\Http\Request;

/**
 * A second test gateway, for pages that list more than one.
 */
final class OtherGateway implements PaymentGateway
{
    /**
     * @param  array<string, string>  $settings
     */
    public function __construct(private array $settings) {}

    public static function key(): string
    {
        return 'othergw';
    }

    public static function label(): string
    {
        return 'Other gateway';
    }

    public static function currencies(): array
    {
        return ['INR'];
    }

    public static function settingFields(): array
    {
        return [];
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
