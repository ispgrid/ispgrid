import { Alpine, Livewire } from '../../vendor/livewire/livewire/dist/livewire.esm';

Alpine.store('appearance', {
    value: localStorage.getItem('appearance') ?? 'system',

    set(value) {
        this.value = value;
        localStorage.setItem('appearance', value);

        const dark = value === 'dark' || (value === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.classList.toggle('dark', dark);
    },
});

Livewire.start();
