<?php

namespace App\Payments;

/**
 * Where a gateway sends the customer to pay: a plain redirect, a form the page posts by itself, or the provider's
 * script with its data attributes.
 */
final readonly class GatewayStart
{
    /**
     * @param  'redirect'|'form'|'script'  $kind
     * @param  array<string, string>  $fields  form fields, or the script's data attributes
     */
    private function __construct(
        public string $kind,
        public string $url,
        public array $fields = [],
    ) {}

    public static function redirect(string $url): self
    {
        return new self('redirect', $url);
    }

    /**
     * @param  array<string, string>  $fields
     */
    public static function form(string $action, array $fields): self
    {
        return new self('form', $action, $fields);
    }

    /**
     * @param  array<string, string>  $data
     */
    public static function script(string $src, array $data): self
    {
        return new self('script', $src, $data);
    }
}
