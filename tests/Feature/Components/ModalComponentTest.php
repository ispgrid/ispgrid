<?php

use Illuminate\Support\Facades\Blade;

it('renders the wide size with a fixed header and footer and a scrolling body', function () {
    $html = Blade::render('<x-ui.modal name="t" width="wide"><x-slot:header>Head</x-slot:header>Body<x-slot:footer>Foot</x-slot:footer></x-ui.modal>');

    expect($html)->toContain('max-w-[55rem]')->toContain('max-h-[calc(100dvh-2rem)]')->toContain('overflow-y-auto')->toContain('Head')->toContain('Foot');
});

it('keeps small dialogs without slots as they were', function () {
    $html = Blade::render('<x-ui.modal name="t" width="sm">Body</x-ui.modal>');

    expect($html)->toContain('max-w-sm')->toContain('p-6')->not->toContain('max-h-[calc(100dvh-2rem)]');
});

it('keeps the plain panel when only a header is given', function () {
    $html = Blade::render('<x-ui.modal name="t"><x-slot:header>Head</x-slot:header>Body</x-ui.modal>');

    expect($html)->toContain('Head')->toContain('Body')->toContain('p-6')->not->toContain('max-h-[calc(100dvh-2rem)]')->not->toContain('flex-col');
});
