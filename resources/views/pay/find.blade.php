{{-- Quick pay: search by username or mobile, then pay. $rows: null before a search; each row only a masked username and amounts. --}}
<x-layouts::public :title="__('Pay your bill')" :company="$company" :logo="$logo" :phone="$phone" :phone-link="$phoneLink">
    <div class="grid gap-5">
        <div>
            <x-ui.heading size="lg">{{ __('Pay your bill') }}</x-ui.heading>
            <x-ui.text muted class="mt-1">{{ __('Enter your username or registered mobile number.') }}</x-ui.text>
        </div>

        @if (session('pay_message'))
            <x-ui.alert>{{ session('pay_message') }}</x-ui.alert>
        @endif

        <form method="POST" action="{{ route('pay.find') }}" class="grid gap-3" novalidate>
            @csrf
            <x-ui.input name="who" :value="$who" :label="__('Username or mobile number')" maxlength="100" autocomplete="off" autocapitalize="off" spellcheck="false" required />
            <x-ui.button type="submit">{{ __('Continue') }}</x-ui.button>
        </form>

        @if ($rows === [])
            <x-ui.alert>{{ __('Nothing to pay for this username or number.') }}</x-ui.alert>
        @elseif ($rows !== null)
            <div class="grid gap-3">
                @foreach ($rows as $row)
                    <form method="POST" action="{{ route('pay.start') }}" class="grid gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        @csrf
                        <input type="hidden" name="token" value="{{ $row['token'] }}">
                        <p class="font-mono text-sm">{{ $row['username'] }}</p>
                        <dl class="grid gap-1 text-sm">
                            @if ((float) $row['dues'] > 0)
                                <div class="flex justify-between gap-4"><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Dues') }}</dt><dd class="tabular-nums">{{ $money($row['dues']) }}</dd></div>
                            @endif
                            @if ($row['renewal'] !== null)
                                <div class="flex justify-between gap-4"><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Renewal') }}</dt><dd class="tabular-nums">{{ $money($row['renewal']) }}</dd></div>
                            @endif
                            <div class="mt-1 flex justify-between gap-4 border-t border-zinc-200 pt-2 text-base font-semibold dark:border-zinc-700"><dt>{{ __('Total') }}</dt><dd class="tabular-nums">{{ $money($row['total']) }}</dd></div>
                        </dl>
                        @if (count($gateways) > 1)
                            <div class="grid gap-2">
                                @foreach ($gateways as $gateway)
                                    <x-ui.button type="submit" name="gateway" value="{{ $gateway['key'] }}" :variant="$loop->first ? 'primary' : 'secondary'">{{ __('Pay :amount with :gateway', ['amount' => $money($row['total']), 'gateway' => $gateway['label']]) }}</x-ui.button>
                                @endforeach
                            </div>
                        @else
                            <input type="hidden" name="gateway" value="{{ $gateways[0]['key'] ?? '' }}">
                            <x-ui.button type="submit">{{ __('Pay :amount', ['amount' => $money($row['total'])]) }}</x-ui.button>
                        @endif
                    </form>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts::public>
