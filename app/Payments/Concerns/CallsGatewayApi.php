<?php

namespace App\Payments\Concerns;

use App\Exceptions\GatewayUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;

/**
 * One way for gateways to call a provider's JSON API. Any failure becomes GatewayUnavailable, and its message names
 * the host and path and the HTTP status only: never headers, the body, the query string or a key.
 */
trait CallsGatewayApi
{
    /**
     * @param  array<string, mixed>  $payload  sent as the JSON body, or as the query string for a GET
     * @return array<string, mixed>
     *
     * @throws GatewayUnavailable
     */
    protected function sendJson(PendingRequest $request, string $method, string $url, array $payload = []): array
    {
        $where = $this->describeEndpoint($url);

        try {
            $response = $request->acceptJson()->timeout(20)->send(strtoupper($method), $url, strtoupper($method) === 'GET' ? ['query' => $payload] : ['json' => $payload]);
        } catch (ConnectionException) {
            throw new GatewayUnavailable("The gateway could not be reached ({$where}).");
        }

        if (! $response->successful()) {
            throw new GatewayUnavailable("The gateway answered HTTP {$response->status()} ({$where}).");
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new GatewayUnavailable("The gateway answered something that is not JSON ({$where}).");
        }

        return $decoded;
    }

    private function describeEndpoint(string $url): string
    {
        return (string) parse_url($url, PHP_URL_HOST).(string) parse_url($url, PHP_URL_PATH);
    }
}
