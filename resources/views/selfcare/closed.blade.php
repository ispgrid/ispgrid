{{-- Selfcare while the operator has it switched off (served with a 404 status). --}}
<x-layouts::public :title="__('Selfcare')" :company="$company" :logo="$logo" :phone="$phone" :phone-link="$phoneLink">
    <div class="grid gap-2 text-center">
        <x-ui.heading size="lg">{{ __('Selfcare is not available.') }}</x-ui.heading>
        <x-ui.text muted>{{ __('Call us for help with your connection.') }}</x-ui.text>
    </div>
</x-layouts::public>
