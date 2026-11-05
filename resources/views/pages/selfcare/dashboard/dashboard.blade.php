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

    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-800">
        <x-ui.link :href="route('selfcare.password')">{{ __('Change selfcare password') }}</x-ui.link>
        <form method="POST" action="{{ route('selfcare.logout') }}">
            @csrf
            <x-ui.button type="submit" variant="ghost" size="sm">{{ __('Log out') }}</x-ui.button>
        </form>
    </div>
</div>
