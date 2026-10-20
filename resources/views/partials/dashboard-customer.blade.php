@if ($row['href'])
    <x-ui.link href="{{ $row['href'] }}" wire:navigate class="font-medium">{{ $row['username'] }}</x-ui.link>
@else
    <span class="font-medium">{{ $row['username'] }}</span>
@endif
