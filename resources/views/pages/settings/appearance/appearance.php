<?php

use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Appearance settings')] class extends Component
{
    /**
     * @return array<string, array{icon: string, label: string}>
     */
    #[Computed]
    public function modes(): array
    {
        return [
            'light' => ['icon' => 'sun', 'label' => __('Light')],
            'dark' => ['icon' => 'moon', 'label' => __('Dark')],
            'system' => ['icon' => 'computer-desktop', 'label' => __('System')],
        ];
    }
};
