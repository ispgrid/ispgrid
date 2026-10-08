@props(['name'])

<div role="tabpanel" x-show="active === @js($name)" {{ $attributes }}>
    {{ $slot }}
</div>
