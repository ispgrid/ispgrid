<?php

namespace App\Payments\Concerns;

use App\Exceptions\GatewayUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;

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

        $options = strtoupper($method) === 'GET' ? ($payload === [] ? [] : ['query' => $payload]) : ['json' => $payload];

        try {
            $response = $request->acceptJson()->timeout(20)->send(strtoupper($method), $url, $options);
        } catch (RequestException $exception) {
            throw new GatewayUnavailable("The gateway answered HTTP {$exception->response->status()} ({$where}).", $exception->response->status());
        } catch (ConnectionException|HttpClientException) {
            throw new GatewayUnavailable("The gateway could not be reached ({$where}).");
        }

        if (! $response->successful()) {
            throw new GatewayUnavailable("The gateway answered HTTP {$response->status()} ({$where}).", $response->status());
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
