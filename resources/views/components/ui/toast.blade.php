<div
    x-data="{
        toasts: [],
        add(message, variant) {
            const toast = { id: Date.now() + Math.random(), message, variant: variant ?? 'success' };
            this.toasts.push(toast);
            if (toast.variant !== 'danger') {
                setTimeout(() => this.dismiss(toast.id), 4000);
            }
        },
        dismiss(id) {
            this.toasts = this.toasts.filter(toast => toast.id !== id);
        },
    }"
    x-on:toast.window="add($event.detail.message, $event.detail.variant)"
    @if (session('toast')) x-init="add(@js(session('toast')))" @endif
    aria-live="polite"
    class="pointer-events-none fixed end-4 bottom-4 z-50 flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-2"
>
    {{-- Success toasts fade after 4 seconds; danger toasts stay until dismissed, so a problem is never missed. --}}
    <template x-for="toast in toasts" x-bind:key="toast.id">
        <div
            x-transition.opacity
            x-bind:class="toast.variant === 'danger'
                ? 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200'
                : 'border-green-200 bg-green-50 text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200'"
            class="pointer-events-auto flex items-start gap-3 rounded-lg border px-4 py-3 text-sm shadow-lg"
        >
            <span class="flex-1" x-text="toast.message"></span>
            <button type="button" aria-label="Dismiss" class="-me-1 rounded opacity-70 hover:opacity-100" x-on:click="dismiss(toast.id)">
                <x-ui.icon name="x-mark" class="size-4" />
            </button>
        </div>
    </template>
</div>
