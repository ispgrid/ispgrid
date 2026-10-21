<?php

namespace Tests\Fakes;

use App\Contracts\PaymentGateway;
use App\Exceptions\GatewayUnavailable;
use App\Models\OnlinePayment;
use App\Notifications\SettingField;
use App\Payments\GatewayResult;
use App\Payments\GatewayStart;
use App\Payments\GatewayStatus;
use Illuminate\Http\Request;

/**
 * A payment gateway for tests, and the smallest complete example of one (CONTRIBUTING.md, "Add a payment gateway").
 * Tests decide what the "provider" answers through the static properties.
 */
final class FakeGateway implements PaymentGateway
{
    /** @var array<string, GatewayResult> what status() answers, by public_id */
    public static array $results = [];

    public static bool $refuseStart = false;

    /** @var list<string> public_ids status() was asked about */
    public static array $asked = [];

    /**
     * @param  array<string, string>  $settings
     */
    public function __construct(private array $settings) {}

    public static function reset(): void
    {
        self::$results = [];
        self::$refuseStart = false;
        self::$asked = [];
    }

    public static function key(): string
    {
        return 'fake';
    }

    public static function label(): string
    {
        return 'Test gateway';
    }

    public static function currencies(): array
    {
        return ['INR'];
    }

    public static function settingFields(): array
    {
        return [
            new SettingField('account', 'Account'),
            new SettingField('api_key', 'API key', 'secret'),
        ];
    }

    public function start(OnlinePayment $payment, string $returnUrl): GatewayStart
    {
        if (self::$refuseStart) {
            throw new GatewayUnavailable('The test gateway refused.');
        }

        return GatewayStart::form('https://gateway.test/pay', [
            'account' => $this->settings['account'] ?? '',
            'order' => $payment->public_id,
            'amount' => (string) $payment->amount,
            'return' => $returnUrl,
        ]);
    }

    public function status(OnlinePayment $payment): GatewayResult
    {
        self::$asked[] = $payment->public_id;

        return self::$results[$payment->public_id] ?? new GatewayResult(GatewayStatus::Pending);
    }

    public function verify(Request $request): ?string
    {
        $order = $request->input('order');

        return is_string($order) && $request->input('signature') === hash_hmac('sha256', $order, $this->settings['api_key'] ?? '') ? $order : null;
    }

    /**
     * Marks the payment paid at the "provider", for the amount given (its own amount by default).
     */
    public static function pay(OnlinePayment $payment, ?string $amount = null): void
    {
        self::$results[$payment->public_id] = new GatewayResult(GatewayStatus::Paid, $amount ?? (string) $payment->amount, 'TXN'.$payment->id, now(), 'SUCCESS', ['note' => 'test']);
    }
}
