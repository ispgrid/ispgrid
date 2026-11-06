@php
    // A heading that asks for its own alignment (text-end for number columns) must not also get text-start,
    // which wins in the built CSS.
    $aligned = preg_match('/(^|\s)(\w+:)*text-(start|end|center|left|right)(\s|$)/', (string) $attributes->get('class')) === 1;
@endphp
<th {{ $attributes->class(['px-4 py-2.5 font-medium', 'text-start' => ! $aligned]) }}>{{ $slot }}</th>
