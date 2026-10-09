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

// A collapsible menu group: open while one of its pages is current, otherwise as the viewer last left it.
Alpine.data('navGroup', (name, active) => ({
    open: active,

    init() {
        if (!active) {
            try {
                this.open = localStorage.getItem(`nav-group:${name}`) === 'open';
            } catch {
                // Storage blocked: stay closed.
            }
        }
    },

    toggle() {
        this.open = !this.open;
        try {
            localStorage.setItem(`nav-group:${name}`, this.open ? 'open' : 'closed');
        } catch {
            // Storage blocked: the group still opens, it is just not remembered.
        }
    },
}));

Livewire.start();
