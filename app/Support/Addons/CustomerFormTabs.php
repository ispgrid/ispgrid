<?php

namespace App\Support\Addons;

use App\Contracts\CustomerFormTab;
use InvalidArgumentException;

/**
 * The add-on tabs of the customer form (a singleton), after core's own tabs in the order they were registered.
 */
final class CustomerFormTabs
{
    /**
     * @var array<string, class-string<CustomerFormTab>>
     */
    private array $tabs = [];

    /**
     * @param  class-string<CustomerFormTab>  $tab
     */
    public function register(string $tab): void
    {
        $key = app($tab)->key();

        if (preg_match('/^[a-z0-9-]+$/', $key) !== 1 || in_array($key, ['profile', 'service', 'network', 'attributes'], true)) {
            throw new InvalidArgumentException("A customer form tab key must be lowercase letters, digits and -, and not a core tab: {$key}");
        }

        if (isset($this->tabs[$key])) {
            throw new InvalidArgumentException("Two customer form tabs use the key {$key}.");
        }

        $this->tabs[$key] = $tab;
    }

    /**
     * @return array<string, CustomerFormTab> by key
     */
    public function all(): array
    {
        return array_map(fn (string $tab): CustomerFormTab => app($tab), $this->tabs);
    }
}
