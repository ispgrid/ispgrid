{{-- Runs once Cashfree's script has loaded (the script tag above is not async). Only the escaped values below come from the gateway. --}}
<script>
    if (typeof Cashfree === 'function') {
        Cashfree({ mode: @js($fields['mode'] ?? 'sandbox') }).checkout({ paymentSessionId: @js($fields['session'] ?? ''), redirectTarget: '_self' });
    } else {
        document.getElementById('pay-go-fallback').hidden = false;
    }
</script>
<p id="pay-go-fallback" hidden class="text-sm text-zinc-700 dark:text-zinc-300">
    {{ __('The payment could not be started. Try again in a few minutes.') }}
    <a href="javascript:history.back()" class="underline">{{ __('Back') }}</a>
</p>
