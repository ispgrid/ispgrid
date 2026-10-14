@assets
@vite('resources/js/passkeys.js')
@endassets

<div
    x-data="{
        supported: false,
        secure: window.isSecureContext,
        showForm: false,
        name: '',
        loading: false,
        error: null,
        updateSupport() {
            this.supported = Boolean(window.Passkeys?.isSupported());
        },
        getDefaultPasskeyName() {
            const ua = navigator.userAgent;

            const browser = [
                { pattern: /Edg|Edge/, name: 'Edge' },
                { pattern: /OPR|Opera|OPiOS/, name: 'Opera' },
                { pattern: /Firefox|FxiOS/, name: 'Firefox' },
                { pattern: /Chrome|CriOS/, name: 'Chrome' },
                { pattern: /Safari/, name: 'Safari' },
            ].find(({ pattern }) => pattern.test(ua))?.name;

            const os = [
                { pattern: /iPhone/, name: 'iPhone' },
                { pattern: /iPad|Macintosh(?=.*Mobile)/, name: 'iPad' },
                { pattern: /Android/, name: 'Android' },
                { pattern: /Mac/, name: 'Mac' },
                { pattern: /Windows/, name: 'Windows' },
            ].find(({ pattern }) => pattern.test(ua))?.name;

            return [browser, os].filter(Boolean).join(' on ') || '';
        },
        init() {
            this.name = this.getDefaultPasskeyName();
            this.updateSupport();

            window.addEventListener('passkeys:ready', () => this.updateSupport(), { once: true });
        },
        async register() {
            if (!this.name.trim()) return;

            this.loading = true;
            this.error = null;

            try {
                await window.Passkeys.register({ name: this.name });
                this.name = '';
                this.showForm = false;
                await $wire.loadPasskeys();
            } catch (e) {
                if (e.constructor?.name !== 'UserCancelledError') {
                    this.error = e.message;
                }
            } finally {
                this.loading = false;
            }
        },
        cancel() {
            this.showForm = false;
            this.name = '';
            this.error = null;
        },
    }"
>
    {{-- Browsers offer passkeys only on a secure page (HTTPS, or localhost), so over plain HTTP every browser looks unsupported. --}}
    <template x-if="!secure">
        <x-ui.text muted>{{ __('Passkeys need HTTPS. Open the panel over https:// with a domain name to add one.') }}</x-ui.text>
    </template>

    <template x-if="secure && !supported">
        <x-ui.text muted>{{ __('Passkeys are not supported in this browser.') }}</x-ui.text>
    </template>

    <template x-if="supported && !showForm">
        <div>
            <x-ui.button icon="plus" x-on:click="showForm = true">{{ __('Add passkey') }}</x-ui.button>
        </div>
    </template>

    <template x-if="supported && showForm">
        <div class="space-y-4 rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
            <x-ui.input
                id="passkey-name"
                :label="__('Passkey name')"
                x-model="name"
                :placeholder="__('e.g., MacBook Pro, iPhone')"
                x-on:keydown.enter.prevent="register()"
                x-ref="passkeyNameInput"
                x-init="$nextTick(() => $refs.passkeyNameInput?.focus())"
                :description="__('Give this passkey a name to help you identify it later.')"
            />

            <p x-show="error" x-text="error" x-cloak class="text-sm text-red-600 dark:text-red-400"></p>

            <div class="flex gap-2">
                <x-ui.button x-on:click="register()" x-bind:disabled="loading || !name.trim()">
                    <span x-show="!loading">{{ __('Register passkey') }}</span>
                    <span x-show="loading" x-cloak>{{ __('Registering...') }}</span>
                </x-ui.button>
                <x-ui.button variant="ghost" x-on:click="cancel()">{{ __('Cancel') }}</x-ui.button>
            </div>
        </div>
    </template>
</div>
