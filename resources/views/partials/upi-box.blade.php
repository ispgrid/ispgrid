{{-- The operator's UPI details for a customer who pays by hand: QR picture, UPI ID with Copy, number, note. $upi is a
     UpiDetails; $heading is optional (defaults to "Or pay by UPI"). --}}
<div class="grid grid-cols-1 gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
    <p class="text-sm font-semibold">{{ $heading ?? __('Or pay by UPI') }}</p>

    @if ($upi->qrUrl)
        <img src="{{ $upi->qrUrl }}" alt="{{ __('UPI QR') }}" class="size-48 max-w-full justify-self-center rounded-lg border border-zinc-200 bg-white object-contain dark:border-zinc-700">
    @endif

    <dl class="grid grid-cols-1 gap-2 text-sm">
        @if ($upi->id !== '')
            <div class="flex items-center justify-between gap-3" x-data="{ copied: false }">
                <dt class="text-zinc-600 dark:text-zinc-400">{{ __('UPI ID') }}</dt>
                <dd class="flex min-w-0 items-center gap-2">
                    <span class="break-all font-mono">{{ $upi->id }}</span>
                    <button
                        type="button"
                        class="shrink-0 rounded-md border border-zinc-300 px-2 py-1 text-xs font-medium hover:bg-zinc-50 focus:outline-2 focus:outline-offset-1 focus:outline-deep-green dark:border-zinc-600 dark:hover:bg-zinc-800 dark:focus:outline-signal"
                        x-on:click="navigator.clipboard.writeText(@js($upi->id)).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
                    >
                        <span x-show="! copied">{{ __('Copy') }}</span>
                        <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                    </button>
                </dd>
            </div>
        @endif
        @if ($upi->number !== '')
            <div class="flex justify-between gap-3">
                <dt class="text-zinc-600 dark:text-zinc-400">{{ __('UPI number') }}</dt>
                <dd class="font-mono">{{ $upi->number }}</dd>
            </div>
        @endif
    </dl>

    @if ($upi->note !== '')
        <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $upi->note }}</p>
    @endif
</div>
