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

    /**
     * @return array{kind: string, url: string, fields: array<string, string>}
     */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'url' => $this->url, 'fields' => $this->fields];
    }

    /**
     * A start kept with its attempt, or null when it is not one this class makes.
     *
     * @param  array<mixed>  $stored
     */
    public static function fromArray(array $stored): ?self
    {
        $kind = $stored['kind'] ?? null;
        $url = $stored['url'] ?? null;
        $fields = $stored['fields'] ?? [];

        if (! in_array($kind, ['redirect', 'form', 'script'], true) || ! is_string($url) || ! is_array($fields)) {
            return null;
        }

        return new self($kind, $url, array_map(strval(...), array_filter($fields, is_scalar(...))));
    }
}
