<div
    x-data="{
        toasts: [],
        add(message, variant) {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, message, variant: variant ?? 'success' });
            setTimeout(() => this.toasts = this.toasts.filter(toast => toast.id !== id), 4000);
        },
    }"
    x-on:toast.window="add($event.detail.message, $event.detail.variant)"
    aria-live="polite"
    class="pointer-events-none fixed end-4 bottom-4 z-50 flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-2"
>
    <template x-for="toast in toasts" x-bind:key="toast.id">
        <div
            x-transition.opacity
            x-bind:class="toast.variant === 'danger'
                ? 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200'
                : 'border-green-200 bg-green-50 text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200'"
            class="pointer-events-auto rounded-lg border px-4 py-3 text-sm shadow-lg"
            x-text="toast.message"
        ></div>
    </template>
</div>
