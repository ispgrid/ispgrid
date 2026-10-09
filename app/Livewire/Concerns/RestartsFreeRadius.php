<?php

namespace App\Livewire\Concerns;

use App\Actions\Radius\RestartFreeRadius;

/**
 * For pages that save what FreeRADIUS loads only at start (routers, its settings). Use with ShowsToasts.
 */
trait RestartsFreeRadius
{
    /**
     * Restarts FreeRADIUS after a committed save and tells staff how it went. The save stays either way.
     */
    protected function restartFreeRadiusAfter(string $saved): void
    {
        $problem = app(RestartFreeRadius::class)->handle();

        if ($problem === null) {
            $this->toast(__(':saved FreeRADIUS restarted.', ['saved' => $saved]));

            return;
        }

        $this->toast($saved.' '.$problem, 'danger');
    }
}
