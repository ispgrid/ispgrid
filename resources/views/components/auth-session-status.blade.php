@props([
    'status',
])

@if ($status)
    <div {{ $attributes->merge(['class' => 'font-medium text-sm text-deep-green dark:text-signal']) }}>
        {{ $status }}
    </div>
@endif
