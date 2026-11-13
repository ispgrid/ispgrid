<div class="grid grid-cols-1 gap-5">
    <div>
        <x-ui.heading size="lg">{{ __('Choose a connection') }}</x-ui.heading>
        <x-ui.text muted class="mt-1">{{ __('These connections share your mobile number.') }}</x-ui.text>
    </div>

    <ul class="grid grid-cols-1 gap-3">
        @foreach ($this->connections as $connection)
            <li wire:key="connection-{{ $connection['id'] }}">
                <button
                    type="button"
                    wire:click="open({{ $connection['id'] }})"
                    class="grid w-full grid-cols-1 gap-1 rounded-lg border border-zinc-300 p-4 text-start hover:border-deep-green focus:outline-2 focus:outline-offset-1 focus:outline-deep-green dark:border-zinc-600 dark:hover:border-signal dark:focus:outline-signal"
                >
                    <span class="flex flex-wrap items-center gap-2 font-medium">
                        {{ $connection['name'] }}
                        @if ($connection['current'])
                            <x-ui.badge>{{ __('Open now') }}</x-ui.badge>
                        @endif
                    </span>
                    @if ($connection['address'])
                        <span class="text-sm text-zinc-600 dark:text-zinc-400">{{ $connection['address'] }}</span>
                    @endif
                    <span class="font-mono text-sm text-zinc-600 dark:text-zinc-400">{{ $connection['username'] }}</span>
                    @if ($connection['account_number'])
                        <span class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Account ID: :id', ['id' => $connection['account_number']]) }}</span>
                    @endif
                </button>
            </li>
        @endforeach
    </ul>

    <form method="POST" action="{{ route('selfcare.logout') }}">
        @csrf
        <x-ui.button type="submit" variant="ghost" class="w-full">{{ __('Log out') }}</x-ui.button>
    </form>
</div>
