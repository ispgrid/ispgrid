<?php

use App\Exceptions\GatewayUnavailable;
use App\Payments\Concerns\CallsGatewayApi;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
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

        /** @return array<string, mixed> */
        public function callWith(PendingRequest $request): array
        {
            return $this->sendJson($request, 'post', 'https://api.gateway.test/v1/orders', ['amount' => 100]);
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

it('hides the body when the request is built to throw or retry', function (string $how) {
    Http::fake(['https://api.gateway.test/*' => Http::response(['error' => 'bad key sk_live_SECRET'], 500)]);
    $caller = apiCaller();
    $build = fn () => $how === 'throw' ? $caller->callWith(Http::throw()) : $caller->callWith(Http::retry(2, 0));

    try {
        $build();
    } catch (GatewayUnavailable $exception) {
        expect($exception->getMessage())->toContain('500')->toContain('api.gateway.test/v1/orders')
            ->not->toContain('sk_live_SECRET')->and($exception->getPrevious())->toBeNull();

        return;
    }

    $this->fail('Expected GatewayUnavailable.');
})->with(['throw', 'retry']);

it('keeps a query string already on the url for a GET without a payload', function () {
    Http::fake(['https://api.gateway.test/*' => Http::response(['ok' => true], 200)]);

    apiCaller()->call('get', 'https://api.gateway.test/v1/orders?id=o1');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'id=o1'));
});

it('carries the HTTP status on the exception, and none when the gateway was not reached', function () {
    Http::fake(['https://api.gateway.test/*' => Http::response(['error' => 'boom'], 500)]);
    $status = fn (): ?int => rescue(fn () => apiCaller()->call('get', 'https://api.gateway.test/v1/orders/o1'), fn (GatewayUnavailable $e): ?int => $e->httpStatus, false);

    expect($status())->toBe(500);

    Http::fake(['https://api.gateway.test/*' => Http::failedConnection()]);
    expect($status())->toBeNull();
});
