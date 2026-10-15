<?php

namespace App\Support;

use App\Contracts\SessionKeeper;

/**
 * The registered session keepers (a singleton). Add-ons register theirs in a service provider.
 */
final class SessionKeepers
{
    /**
     * @var list<class-string<SessionKeeper>>
     */
    private array $keepers = [];

    /**
     * @param  class-string<SessionKeeper>  $keeper
     */
    public function register(string $keeper): void
    {
        if (! in_array($keeper, $this->keepers, true)) {
            $this->keepers[] = $keeper;
        }
    }

    /**
     * @return list<SessionKeeper>
     */
    public function all(): array
    {
        return array_map(fn (string $keeper): SessionKeeper => app($keeper), $this->keepers);
    }
}
