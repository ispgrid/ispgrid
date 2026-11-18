{{-- How a payment started in Selfcare went; shown to its owner only. --}}
<div class="grid grid-cols-1 gap-4" @if ($this->summary['state'] === 'pending') wire:poll.10s @endif>
    @if ($this->summary['state'] === 'received')
        <x-ui.alert variant="success">{{ __('Payment of :amount received. Thank you!', ['amount' => $this->summary['amount']]) }}</x-ui.alert>
        @foreach ($this->summary['receipts'] as $receipt)
            <x-ui.link :href="$receipt['url']" wire:key="receipt-{{ $receipt['number'] }}">{{ __('Receipt: :numbers', ['numbers' => $receipt['number']]) }}</x-ui.link>
        @endforeach
        @if ($this->summary['expiry'])
            <x-ui.text>{{ __('Your connection now runs until :date.', ['date' => $this->summary['expiry']]) }}</x-ui.text>
        @endif
    @elseif ($this->summary['state'] === 'pending')
        <x-ui.alert variant="warning">{{ __('We are confirming your payment of :amount. This can take a few minutes; you do not need to pay again.', ['amount' => $this->summary['amount']]) }}</x-ui.alert>
        <x-ui.text muted>{{ __('This page updates by itself.') }}</x-ui.text>
    @else
        <x-ui.alert variant="danger">{{ __('The payment did not go through. If money left your account, your bank returns it in a few days.') }}</x-ui.alert>
        <x-ui.button href="{{ route('selfcare.dashboard') }}">{{ __('Try again') }}</x-ui.button>
    @endif

    <x-ui.link :href="route('selfcare.dashboard')">{{ __('Back to Selfcare') }}</x-ui.link>
</div>
