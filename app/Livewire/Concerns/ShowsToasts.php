<?php

namespace App\Livewire\Concerns;

/**
 * Shows a short message in the corner of the screen (rendered by <x-ui.toast /> in the layouts).
 */
trait ShowsToasts
{
    /**
     * @param  'success'|'danger'  $variant
     */
    protected function toast(string $message, string $variant = 'success'): void
    {
        $this->dispatch('toast', message: $message, variant: $variant);
    }
}
