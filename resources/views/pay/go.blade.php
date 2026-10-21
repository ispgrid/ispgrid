{{-- Hands the customer to the gateway: a form that posts itself, or the gateway's script. A button covers browsers without scripts. --}}
<x-layouts::public :title="__('Pay your bill')" :company="$company" :logo="$logo" :phone="$phone" :phone-link="$phoneLink">
    <div class="grid gap-4 text-center">
        <x-ui.text>{{ __('Taking you to the payment page...') }}</x-ui.text>

        @if ($start->kind === 'form')
            <form method="POST" action="{{ $start->url }}" id="pay-go">
                @foreach ($start->fields as $field => $value)
                    <input type="hidden" name="{{ $field }}" value="{{ $value }}">
                @endforeach
                <x-ui.button type="submit">{{ __('Continue to payment') }}</x-ui.button>
            </form>
            <script>document.getElementById('pay-go').submit();</script>
        @else
            <script src="{{ $start->url }}" @foreach ($start->fields as $field => $value) data-{{ $field }}="{{ $value }}" @endforeach></script>
        @endif
    </div>
</x-layouts::public>
