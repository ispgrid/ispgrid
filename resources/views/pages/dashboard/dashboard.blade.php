<section class="grid gap-6">
    <div>
        <x-ui.heading level="1" size="xl">{{ __('Dashboard') }}</x-ui.heading>
        <x-ui.text muted class="mt-1">{{ __('Who is online, who expires soon, and the latest refused logins.') }}</x-ui.text>
    </div>

    @foreach ($this->outdatedRouters as $outdated)
        <x-ui.alert variant="warning" wire:key="outdated-{{ $outdated['id'] }}">
            <span class="font-medium">{{ $outdated['router'] }}</span>
            <span>{{ $outdated['warning'] }}</span>
            <x-ui.link href="{{ route('routers.index') }}" wire:navigate>{{ __('Open NAS') }}</x-ui.link>
        </x-ui.alert>
    @endforeach

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($this->figureCards as $figure)
            <a href="{{ $figure['href'] }}" wire:navigate class="rounded-xl border border-zinc-200 bg-white p-5 hover:border-zinc-400 focus-visible:outline-2 focus-visible:outline-zinc-500 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-500">
                <span class="block text-sm text-zinc-500 dark:text-zinc-400">{{ $figure['label'] }}</span>
                <span class="mt-2 block text-3xl font-semibold tabular-nums">{{ number_format($figure['value']) }}</span>
            </a>
        @endforeach
    </div>

    <x-ui.card>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <x-ui.heading>{{ __('Latest refused logins') }}</x-ui.heading>
            <x-ui.link href="{{ route('login-log.index', ['result' => 'rejected']) }}" wire:navigate class="text-sm">{{ __('Open the login log') }}</x-ui.link>
        </div>
        @if ($this->failedLogins === [])
            <x-ui.text muted class="mt-4">{{ __('No refused login yet.') }}</x-ui.text>
        @else
            <x-ui.table class="mt-4">
                <x-slot:head>
                    <x-ui.th>{{ __('When') }}</x-ui.th>
                    <x-ui.th>{{ __('Username') }}</x-ui.th>
                    <x-ui.th>{{ __('Result') }}</x-ui.th>
                    <x-ui.th>{{ __('Reason') }}</x-ui.th>
                    <x-ui.th>{{ __('NAS') }}</x-ui.th>
                </x-slot:head>
                @foreach ($this->failedLogins as $login)
                    <tr wire:key="failed-{{ $login['id'] }}">
                        <x-ui.td class="tabular-nums whitespace-nowrap">{{ $login['when'] }}</x-ui.td>
                        <x-ui.td class="font-medium">{{ $login['username'] }}</x-ui.td>
                        <x-ui.td><x-ui.badge :color="$login['color']">{{ $login['result'] }}</x-ui.badge></x-ui.td>
                        <x-ui.td class="break-words">{{ $login['reason'] }}</x-ui.td>
                        <x-ui.td>{{ $login['router'] }}</x-ui.td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>
</section>
