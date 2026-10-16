<?php

namespace App\Concerns;

/**
 * For pages behind a permission. Livewire runs mount() only when the page opens, so the page's access check runs
 * again on every later request of the open page (hydrate): a permission taken away stops the page at once.
 */
trait AuthorizesEveryRequest
{
    /**
     * The page's access check. mount() calls it too.
     */
    abstract protected function authorizeAccess(): void;

    public function hydrateAuthorizesEveryRequest(): void
    {
        $this->authorizeAccess();
    }
}
