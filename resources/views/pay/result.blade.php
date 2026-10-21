{{-- How a quick pay attempt went. Keyed by its random id; shows the amount and receipt numbers, nothing about the customer. --}}
<x-layouts::public :title="__('Payment')" :company="$company" :logo="$logo" :phone="$phone" :phone-link="$phoneLink">
    <div class="grid gap-4">
        @if ($state === 'received')
            <x-ui.alert variant="success">{{ __('Payment of :amount received. Thank you!', ['amount' => $money((string) $payment->amount)]) }}</x-ui.alert>
            @if ($receipts !== [])
                <x-ui.text>{{ __('Receipt: :numbers', ['numbers' => implode(', ', $receipts)]) }}</x-ui.text>
            @endif
            <x-ui.text muted>{{ __('A confirmation is sent to your registered mobile number.') }}</x-ui.text>
        @elseif ($state === 'pending')
            <x-ui.alert variant="warning">{{ __('We are confirming your payment of :amount. This can take a few minutes; you do not need to pay again.', ['amount' => $money((string) $payment->amount)]) }}</x-ui.alert>
            <x-ui.button variant="secondary" href="{{ route('pay.result', $payment->public_id) }}">{{ __('Check again') }}</x-ui.button>
        @else
            <x-ui.alert variant="danger">{{ __('The payment did not go through. If money left your account, your bank returns it in a few days.') }}</x-ui.alert>
            <x-ui.button href="{{ route('pay.show') }}">{{ __('Try again') }}</x-ui.button>
        @endif
    </div>
</x-layouts::public>
