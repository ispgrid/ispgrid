{{-- Runs once Cashfree's script has loaded (the script tag above is not async). Only the escaped values below come from the gateway. --}}
<script>
    if (typeof Cashfree === 'function') {
        Cashfree({ mode: @js($fields['mode'] ?? 'sandbox') }).checkout({ paymentSessionId: @js($fields['session'] ?? ''), redirectTarget: '_self' });
    }
</script>
