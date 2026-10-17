<?php

namespace App\Support\Addons;

use App\Models\Account;
use App\Models\User;
use Closure;

/**
 * Add-on cards on the customer page (a singleton): Livewire components shown after the Service and Profile cards,
 * each given the customer's id as "accountId". Lower $order comes first.
 */
final class CustomerCards
{
    /**
     * @var array<string, array{visible: Closure(User, Account): bool, order: int}>
     */
    private array $cards = [];

    /**
     * @param  string  $component  the Livewire component name, e.g. "documents::customer-card"
     * @param  Closure(User, Account): bool  $visible
     */
    public function register(string $component, Closure $visible, int $order = 50): void
    {
        $this->cards[$component] = ['visible' => $visible, 'order' => $order];
    }

    /**
     * The components to show this staff member on this customer's page, in order.
     *
     * @return list<string>
     */
    public function for(User $user, Account $account): array
    {
        $cards = array_filter($this->cards, fn (array $card): bool => ($card['visible'])($user, $account));
        uasort($cards, fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_keys($cards);
    }
}
