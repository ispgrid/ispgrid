import { Alpine, Livewire } from '../../vendor/livewire/livewire/dist/livewire.esm';

/**
 * Sets the dark class on <html> from a stored appearance ('light', 'dark' or 'system').
 */
const applyAppearance = (value) => {
    const dark = value === 'dark' || (value === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', dark);
};

Alpine.store('appearance', {
    value: localStorage.getItem('appearance') ?? 'system',

    set(value) {
        this.value = value;
        localStorage.setItem('appearance', value);
        applyAppearance(value);
    },
});

// wire:navigate swaps in the next page's <html> attributes, which drops the dark class the head script set
// on the first load. Put it back during the swap, before the page paints.
document.addEventListener('livewire:navigating', (event) => {
    event.detail.onSwap(() => applyAppearance(localStorage.getItem('appearance') ?? 'system'));
});

Livewire.start();
