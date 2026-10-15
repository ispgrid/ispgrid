<?php

namespace App\Support\Navigation;

use App\Models\User;
use Closure;

/**
 * One menu entry. $label returns the text in the staff member's language; $visible(User) decides who sees it;
 * $active lists the route patterns that mark it current. Lower $order comes first.
 */
final readonly class NavItem
{
    /**
     * @param  Closure(): string  $label
     * @param  list<string>  $active
     * @param  Closure(User): bool  $visible
     */
    public function __construct(
        public string $group,
        public Closure $label,
        public string $route,
        public string $icon,
        public array $active,
        public Closure $visible,
        public int $order,
    ) {}
}
