<div class="grid gap-6">
    @if (session('selfcare_message'))
        <x-ui.alert>{{ session('selfcare_message') }}</x-ui.alert>
    @endif

    <section class="grid gap-3">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <x-ui.heading size="lg">{{ $this->summary['name'] }}</x-ui.heading>
                <x-ui.text muted class="font-mono text-sm">{{ $this->summary['username'] }}</x-ui.text>
            </div>
            <x-ui.badge :color="$this->summary['color']">{{ $this->summary['status'] }}</x-ui.badge>
        </div>
        <dl class="grid gap-2 text-sm">
            <div class="flex justify-between gap-4">
                <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Plan') }}</dt>
                <dd class="text-right">{{ $this->summary['plan'] ?? __('None') }}</dd>
            </div>
            @if ($this->summary['expiry'])
                <div class="flex justify-between gap-4">
                    <dt class="text-zinc-600 dark:text-zinc-400">{{ $this->summary['expired'] ? __('Expired on') : __('Expires on') }}</dt>
                    <dd @class(['text-right tabular-nums', 'font-medium text-red-600 dark:text-red-400' => $this->summary['expired']])>{{ $this->summary['expiry'] }}</dd>
                </div>
            @endif
        </dl>

        @if ($this->renew['kind'] === 'pay')
            <x-ui.button :href="$this->renew['url']" class="w-full">{{ $this->renew['label'] }}</x-ui.button>
        @elseif ($this->renew['kind'] === 'call')
            <x-ui.text class="text-sm"><a href="{{ $this->renew['url'] }}" class="underline-offset-2 hover:underline">{{ $this->renew['label'] }}</a></x-ui.text>
        @elseif ($this->renew['kind'] === 'opens')
            <x-ui.text muted class="text-sm">{{ $this->renew['label'] }}</x-ui.text>
        @endif
    </section>

    @if ($this->connection !== [])
        <section class="grid gap-3 border-t border-zinc-200 pt-5 dark:border-zinc-800">
            <x-ui.heading>{{ __('Connection details') }}</x-ui.heading>
            <dl class="grid gap-2 text-sm">
                @foreach ($this->connection as $label => $value)
                    <div class="flex justify-between gap-4">
                        <dt class="text-zinc-600 dark:text-zinc-400">{{ $label }}</dt>
                        <dd class="font-mono">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>
    @endif

    @if ($this->sessions !== [])
        <section class="grid gap-3 border-t border-zinc-200 pt-5 dark:border-zinc-800">
            <x-ui.heading>{{ __('Online now') }}</x-ui.heading>
            @foreach ($this->sessions as $session)
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Since') }}</dt><dd class="tabular-nums">{{ $session['started'] }}</dd></div>
                    <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('IP address') }}</dt><dd class="font-mono">{{ $session['ip'] }}</dd></div>
                    <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Online for') }}</dt><dd>{{ $session['uptime'] }}</dd></div>
                    <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Data') }}</dt><dd class="tabular-nums">{{ $session['data'] }}</dd></div>
                </dl>
            @endforeach
        </section>
    @endif

    @if ($this->usage !== null)
        <section class="grid gap-3 border-t border-zinc-200 pt-5 dark:border-zinc-800">
            <x-ui.heading>{{ __('Usage') }}</x-ui.heading>
            <div class="flex justify-between gap-4 text-sm">
                <span class="text-zinc-600 dark:text-zinc-400">{{ __('Data used since the last recharge') }}</span>
                <span class="font-medium tabular-nums">{{ $this->usage['period'] }}</span>
            </div>
            @if ($this->usage['days'] !== [])
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-zinc-600 dark:text-zinc-400">
                            <th class="py-1 font-normal">{{ __('Day') }}</th>
                            <th class="py-1 text-right font-normal">{{ __('Download') }}</th>
                            <th class="py-1 text-right font-normal">{{ __('Upload') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->usage['days'] as $day)
                            <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                <td class="py-1 tabular-nums">{{ $day['day'] }}</td>
                                <td class="py-1 text-right tabular-nums">{{ $day['download'] }}</td>
                                <td class="py-1 text-right tabular-nums">{{ $day['upload'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    @endif

    @if ($this->logins !== [])
        <section class="grid gap-3 border-t border-zinc-200 pt-5 dark:border-zinc-800">
            <x-ui.heading>{{ __('Recent logins') }}</x-ui.heading>
            <ul class="grid gap-1.5 text-sm">
                @foreach ($this->logins as $login)
                    <li class="flex items-center justify-between gap-4">
                        <span class="tabular-nums">{{ $login['when'] }}</span>
                        <x-ui.badge :color="$login['color']">{{ $login['result'] }}</x-ui.badge>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-800">
        <x-ui.link :href="route('selfcare.password')">{{ __('Change selfcare password') }}</x-ui.link>
        <form method="POST" action="{{ route('selfcare.logout') }}">
            @csrf
            <x-ui.button type="submit" variant="ghost" size="sm">{{ __('Log out') }}</x-ui.button>
        </form>
    </div>
</div>
