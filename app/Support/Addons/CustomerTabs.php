<?php

namespace App\Support\Addons;

use App\Models\Account;
use App\Models\User;
use Closure;
use InvalidArgumentException;

/**
 * Add-on tabs on the customer page (a singleton): each one a Livewire component given the customer's id as
 * "accountId", shown when its tab is open. Core's tabs have the orders in CORE_ORDERS, so an add-on tab with order 50
 * comes after Connection and before Recharges; lower $order comes first.
 */
final class CustomerTabs
{
    /**
     * Core's own tabs and their order.
     */
    public const CORE_ORDERS = ['details' => 10, 'connection' => 20, 'recharges' => 70, 'billing' => 75, 'messages' => 80, 'activity' => 90];

    /**
     * @var array<string, array{label: Closure(): string, component: string, visible: Closure(User, Account): bool, order: int}>
     */
    private array $tabs = [];

    /**
     * @param  string  $key  the tab's name in the page address (?tab=documents): lowercase letters, digits and "-"
     * @param  Closure(): string  $label  returns the tab's title, translated (it runs when the page is shown, in the staff member's language)
     * @param  string  $component  the Livewire component name, e.g. "documents::customer-tab"
     * @param  Closure(User, Account): bool  $visible
     */
    public function register(string $key, Closure $label, string $component, Closure $visible, int $order = 50): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $key) !== 1 || array_key_exists($key, self::CORE_ORDERS)) {
            throw new InvalidArgumentException("Customer tab key [{$key}] must be lowercase letters, digits or \"-\", and not one of core's tabs.");
        }

        $this->tabs[$key] = ['label' => $label, 'component' => $component, 'visible' => $visible, 'order' => $order];
    }

    /**
     * The add-on tabs this staff member sees on this customer's page, in order.
     *
     * @return array<string, array{label: string, component: string, order: int}>
     */
    public function for(User $user, Account $account): array
    {
        $tabs = array_filter($this->tabs, fn (array $tab): bool => ($tab['visible'])($user, $account));
        uasort($tabs, fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_map(fn (array $tab): array => [
            'label' => ($tab['label'])(),
            'component' => $tab['component'],
            'order' => $tab['order'],
        ], $tabs);
    }
}
