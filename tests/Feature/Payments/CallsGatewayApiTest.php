<?php

use App\Exceptions\GatewayUnavailable;
use App\Payments\Concerns\CallsGatewayApi;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

function apiCaller(): object
{
    return new class
    {
        use CallsGatewayApi;

        /** @return array<string, mixed> */
        public function call(string $method, string $url, array $payload = []): array
        {
            return $this->sendJson(Http::withToken('sk_live_SECRET'), $method, $url, $payload);
        }
    };
}

it('sends the payload and returns the decoded answer', function () {
    Http::fake(['https://api.gateway.test/*' => Http::response(['id' => 'o1'], 200)]);

    expect(apiCaller()->call('post', 'https://api.gateway.test/v1/orders', ['amount' => 100]))->toBe(['id' => 'o1']);
    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['amount'] === 100);
});

it('sends a GET without a body', function () {
    Http::fake(['https://api.gateway.test/*' => Http::response(['ok' => true], 200)]);

    expect(apiCaller()->call('get', 'https://api.gateway.test/v1/orders/o1'))->toBe(['ok' => true]);
});

it('names the host path and status only when the gateway answers an error', function () {
    Http::fake(['https://api.gateway.test/*' => Http::response(['error' => 'bad key sk_live_SECRET'], 500)]);

    try {
        apiCaller()->call('post', 'https://api.gateway.test/v1/orders?key=sk_live_SECRET', ['amount' => 100]);
    } catch (GatewayUnavailable $exception) {
        expect($exception->getMessage())->toContain('api.gateway.test/v1/orders')->toContain('500')
            ->not->toContain('sk_live_SECRET')->not->toContain('bad key');

        return;
    }

    $this->fail('Expected GatewayUnavailable.');
});

it('turns a connection failure into GatewayUnavailable without its text', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error: https://x/?key=sk_live_SECRET'));

    try {
        apiCaller()->call('post', 'https://api.gateway.test/v1/orders');
    } catch (GatewayUnavailable $exception) {
        expect($exception->getMessage())->toContain('api.gateway.test/v1/orders')->not->toContain('sk_live_SECRET');

        return;
    }

    $this->fail('Expected GatewayUnavailable.');
});

it('turns a non-JSON success into GatewayUnavailable', function () {
    Http::fake(['https://api.gateway.test/*' => Http::response('<html>hello sk_live_SECRET</html>', 200)]);

    expect(fn () => apiCaller()->call('post', 'https://api.gateway.test/v1/orders'))
        ->toThrow(GatewayUnavailable::class, 'api.gateway.test/v1/orders');
});
