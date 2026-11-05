<div class="grid gap-6">
    @if (session('selfcare_message'))
        <x-ui.alert>{{ session('selfcare_message') }}</x-ui.alert>
    @endif

    <div>
        <x-ui.heading size="lg">{{ $this->account->fullname }}</x-ui.heading>
        <x-ui.text muted class="mt-1 font-mono">{{ $this->account->username }}</x-ui.text>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-800">
        <x-ui.link :href="route('selfcare.password')">{{ __('Change selfcare password') }}</x-ui.link>
        <form method="POST" action="{{ route('selfcare.logout') }}">
            @csrf
            <x-ui.button type="submit" variant="ghost" size="sm">{{ __('Log out') }}</x-ui.button>
        </form>
    </div>
</div>
