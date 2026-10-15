<?php

namespace App\Support\Navigation;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/**
 * The side menu: groups and their items. Core registers its own menu here (AppServiceProvider); add-ons add
 * items with an order between core's (10, 20, ...).
 */
final class Navigation
{
    /**
     * @var array<string, array{heading: ?Closure, collapsible: bool, order: int}>
     */
    private array $groups = [];

    /**
     * @var list<NavItem>
     */
    private array $items = [];

    /**
     * @param  (Closure(): string)|null  $heading
     */
    public function group(string $name, ?Closure $heading, bool $collapsible, int $order): void
    {
        $this->groups[$name] = ['heading' => $heading, 'collapsible' => $collapsible, 'order' => $order];
    }

    public function add(NavItem $item): void
    {
        $this->items[] = $item;
    }

    /**
     * The groups this staff member sees, each with its visible items; a group without any is left out.
     *
     * @return list<array{name: string, heading: ?string, collapsible: bool, active: bool, items: list<array{href: string, icon: string, label: string, current: bool}>}>
     */
    public function forUser(?User $user, Request $request): array
    {
        if ($user === null) {
            return [];
        }

        $groups = $this->groups;
        uasort($groups, fn (array $a, array $b): int => $a['order'] <=> $b['order']);
        $menu = [];

        foreach ($groups as $name => $group) {
            $items = array_values(array_filter($this->items, fn (NavItem $item): bool => $item->group === $name && ($item->visible)($user)));
            usort($items, fn (NavItem $a, NavItem $b): int => $a->order <=> $b->order);

            if ($items === []) {
                continue;
            }

            $rows = array_map(fn (NavItem $item): array => [
                'href' => route($item->route),
                'icon' => $item->icon,
                'label' => ($item->label)(),
                'current' => $request->routeIs(...$item->active),
            ], $items);

            $menu[] = [
                'name' => $name,
                'heading' => $group['heading'] === null ? null : ($group['heading'])(),
                'collapsible' => $group['collapsible'],
                'active' => in_array(true, array_column($rows, 'current'), true),
                'items' => $rows,
            ];
        }

        return $menu;
    }
}
