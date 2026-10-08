@props(['title', 'description'])

<div class="flex flex-col gap-1 text-center">
    <x-ui.heading level="1" size="xl">{{ $title }}</x-ui.heading>
    <x-ui.text muted>{{ $description }}</x-ui.text>
</div>
