<?php

namespace Tests\Fakes;

use App\Contracts\PaymentGateway;
use App\Contracts\UntestedGateway;
use App\Models\OnlinePayment;
use App\Notifications\SettingField;
use App\Payments\GatewayResult;
use App\Payments\GatewayStart;
use App\Payments\GatewayStatus;
use Illuminate\Http\Request;

/**
 * A gateway that has never been tried with a live account, to test the warning on its settings card.
 */
final class UntestedFakeGateway implements PaymentGateway, UntestedGateway
{
    /**
     * @param  array<string, string>  $settings
     */
    public function __construct(private array $settings) {}

    public static function key(): string
    {
        return 'untested';
    }

    public static function label(): string
    {
        return 'Untested gateway';
    }

    public static function currencies(): array
    {
        return ['INR'];
    }

    public static function settingFields(): array
    {
        return [new SettingField('api_key', 'API key', 'secret')];
    }

    public function start(OnlinePayment $payment, string $returnUrl): GatewayStart
    {
        return GatewayStart::redirect($returnUrl);
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
