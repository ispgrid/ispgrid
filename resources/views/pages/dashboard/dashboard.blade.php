<section class="grid gap-6">
    <div>
        <x-ui.heading level="1" size="xl">{{ __('Dashboard') }}</x-ui.heading>
        <x-ui.text muted class="mt-1">{{ __('Who is online, who expires soon, and the latest refused logins.') }}</x-ui.text>
    </div>

    @foreach ($this->outdatedRouters as $outdated)
        <x-ui.alert variant="warning" wire:key="outdated-{{ $outdated['id'] }}">
            <span class="font-medium">{{ $outdated['router'] }}</span>
            <span>{{ $outdated['warning'] }}</span>
            @can('view-network')
                <x-ui.link href="{{ route('routers.index') }}" wire:navigate>{{ __('Open NAS') }}</x-ui.link>
            @endcan
        </x-ui.alert>
    @endforeach

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($this->todayFigures as $figure)
            @if ($figure['href'])
                <a href="{{ $figure['href'] }}" wire:navigate class="rounded-xl border border-zinc-200 bg-white p-5 hover:border-zinc-400 focus-visible:outline-2 focus-visible:outline-zinc-500 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-500">
                    <span class="block text-sm text-zinc-600 dark:text-zinc-400">{{ $figure['label'] }}</span>
                    <span class="mt-2 block text-3xl font-semibold tabular-nums">{{ number_format($figure['value']) }}</span>
                </a>
            @else
                <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <span class="block text-sm text-zinc-600 dark:text-zinc-400">{{ $figure['label'] }}</span>
                    <span class="mt-2 block text-3xl font-semibold tabular-nums">{{ number_format($figure['value']) }}</span>
                </div>
            @endif
        @endforeach
    </div>

    @if ($this->moneyFigures !== [])
        <div class="grid gap-3">
            <x-ui.heading>{{ __('Money') }}</x-ui.heading>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($this->moneyFigures as $figure)
                    @if ($figure['href'])
                        <a href="{{ $figure['href'] }}" wire:navigate class="rounded-xl border border-zinc-200 bg-white p-5 hover:border-zinc-400 focus-visible:outline-2 focus-visible:outline-zinc-500 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-500">
                            <span class="block text-sm text-zinc-600 dark:text-zinc-400">{{ $figure['label'] }}</span>
                            <span class="mt-2 block text-2xl font-semibold tabular-nums">{{ $figure['value'] }}</span>
                        </a>
                    @else
                        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                            <span class="block text-sm text-zinc-600 dark:text-zinc-400">{{ $figure['label'] }}</span>
                            <span class="mt-2 block text-2xl font-semibold tabular-nums">{{ $figure['value'] }}</span>
                            @if ($figure['hint'])
                                <span class="mt-1 block text-xs text-zinc-600 dark:text-zinc-400">{{ $figure['hint'] }}</span>
                            @endif
                        </div>
                    @endif
                @endforeach
            </div>
            <div class="grid gap-4 lg:grid-cols-2">
                <x-ui.card>
                    <x-ui.heading>{{ __('Sales per plan, this month') }}</x-ui.heading>
                    @if ($this->topPlans === [])
                        <x-ui.text muted class="mt-3">{{ __('No sales yet this month.') }}</x-ui.text>
                    @else
                        <x-ui.table class="mt-3">
                            <x-slot:head>
                                <x-ui.th>{{ __('Plan') }}</x-ui.th>
                                <x-ui.th class="text-end">{{ __('Recharges') }}</x-ui.th>
                                <x-ui.th class="text-end">{{ __('Amount') }}</x-ui.th>
                            </x-slot:head>
                            @foreach ($this->topPlans as $row)
                                <tr wire:key="top-plan-{{ $loop->index }}">
                                    <x-ui.td>{{ $row['plan'] }}</x-ui.td>
                                    <x-ui.td class="text-end tabular-nums">{{ $row['recharges'] }}</x-ui.td>
                                    <x-ui.td class="text-end tabular-nums">{{ $row['amount'] }}</x-ui.td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                    @endif
                </x-ui.card>
            </div>
        </div>
    @endif

    @if ($this->usage !== null)
        <div class="grid gap-3">
            <x-ui.heading>{{ __('Usage') }}</x-ui.heading>
            <div class="grid gap-4 lg:grid-cols-2">
                <x-ui.card>
                    <x-ui.heading>{{ __('Data usage') }}</x-ui.heading>
                    <x-ui.table class="mt-3">
                        <x-slot:head>
                            <x-ui.th>{{ __('Date') }}</x-ui.th>
                            <x-ui.th class="text-end">{{ __('Download') }}</x-ui.th>
                            <x-ui.th class="text-end">{{ __('Upload') }}</x-ui.th>
                            <x-ui.th class="text-end">{{ __('Total') }}</x-ui.th>
                        </x-slot:head>
                        @foreach ($this->usage['days'] as $day)
                            <tr wire:key="usage-day-{{ $loop->index }}">
                                <x-ui.td class="whitespace-nowrap">{{ $day['day'] }}</x-ui.td>
                                <x-ui.td class="text-end tabular-nums">{{ $day['download'] }}</x-ui.td>
                                <x-ui.td class="text-end tabular-nums">{{ $day['upload'] }}</x-ui.td>
                                <x-ui.td class="text-end tabular-nums">{{ $day['total'] }}</x-ui.td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                </x-ui.card>

                <x-ui.card x-data="{ period: 'today' }">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <x-ui.heading>{{ __('Top 5 users') }}</x-ui.heading>
                        <div class="flex gap-1" role="group" aria-label="{{ __('Period') }}">
                            <x-ui.button size="sm" x-bind:class="period === 'today' ? 'bg-zinc-100 dark:bg-zinc-800' : ''" x-on:click="period = 'today'" variant="ghost" x-bind:aria-pressed="period === 'today'">{{ __('Today') }}</x-ui.button>
                            <x-ui.button size="sm" x-bind:class="period === 'month' ? 'bg-zinc-100 dark:bg-zinc-800' : ''" x-on:click="period = 'month'" variant="ghost" x-bind:aria-pressed="period === 'month'">{{ __('This month') }}</x-ui.button>
                        </div>
                    </div>
                    @foreach (['today', 'month'] as $period)
                        <div x-show="period === '{{ $period }}'" @if ($period === 'month') x-cloak @endif class="mt-3">
                            @if ($this->usage[$period] === [])
                                <x-ui.text muted>{{ __('No usage yet.') }}</x-ui.text>
                            @else
                                <ol class="grid gap-2 text-sm">
                                    @foreach ($this->usage[$period] as $row)
                                        <li class="flex justify-between gap-4" wire:key="top-{{ $period }}-{{ $loop->index }}">
                                            @if ($row['href'])
                                                <x-ui.link href="{{ $row['href'] }}" wire:navigate>{{ $row['username'] }}</x-ui.link>
                                            @else
                                                <span>{{ $row['username'] }}</span>
                                            @endif
                                            <span class="tabular-nums">{{ $row['data'] }}</span>
                                        </li>
                                    @endforeach
                                </ol>
                            @endif
                        </div>
                    @endforeach
                </x-ui.card>
            </div>
        </div>
    @endif

    @php($attention = $this->attention)
    <div class="grid gap-4 lg:grid-cols-2">
        @if ($attention['looping'] !== [])
            <x-ui.card>
                <x-ui.heading>{{ __('Logins stuck in a loop') }}</x-ui.heading>
                <x-ui.text muted class="mt-1">{{ __('Refused 10 times or more today. Often an old password saved on a customer device.') }}</x-ui.text>
                <ul class="mt-3 grid gap-1 text-sm">
                    @foreach ($attention['looping'] as $login)
                        <li class="flex justify-between gap-4" wire:key="looping-{{ $login['username'] }}">
                            @if ($login['href'])
                                <x-ui.link href="{{ $login['href'] }}" wire:navigate>{{ $login['username'] }}</x-ui.link>
                            @else
                                <span>{{ $login['username'] }}</span>
                            @endif
                            <span class="tabular-nums">{{ trans_choice(':count refusal|:count refusals', $login['count']) }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif

        @if ($attention['quiet'] !== [])
            <x-ui.card>
                <x-ui.heading>{{ __('NAS gone quiet') }}</x-ui.heading>
                <x-ui.text muted class="mt-1">{{ __('Nothing heard for 30 minutes from a NAS that had sessions in the last day.') }}</x-ui.text>
                <ul class="mt-3 grid gap-1 text-sm">
                    @foreach ($attention['quiet'] as $router)
                        <li class="flex justify-between gap-4" wire:key="quiet-{{ $loop->index }}">
                            <span class="font-medium">{{ $router['router'] }}</span>
                            <span class="tabular-nums">{{ __('Last heard :time', ['time' => $router['last_heard']]) }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif

        @if ($attention['messages'] > 0 || $attention['disconnects'] !== [])
            <x-ui.card>
                <x-ui.heading>{{ __('Failed messages and disconnects') }}</x-ui.heading>
                @if ($attention['messages'] > 0)
                    <p class="mt-3 text-sm">
                        {{ trans_choice(':count message failed today.|:count messages failed today.', $attention['messages']) }}
                        @can('view-notification-log')
                            <x-ui.link href="{{ route('notification-log.index', ['status' => 'failed']) }}" wire:navigate>{{ __('Open the message log') }}</x-ui.link>
                        @endcan
                    </p>
                @endif
                @if ($attention['disconnects'] !== [])
                    <x-ui.text muted class="mt-3">{{ __('Could not disconnect, still online:') }}</x-ui.text>
                    <ul class="mt-1 grid gap-1 text-sm">
                        @foreach ($attention['disconnects'] as $disconnect)
                            <li wire:key="disconnect-{{ $disconnect['username'] }}">
                                @include('partials.dashboard-customer', ['row' => $disconnect])
                                <span class="text-zinc-600 dark:text-zinc-400">{{ $disconnect['reason'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        @endif

        @if ($attention['skipped'] !== [])
            <x-ui.card>
                <x-ui.heading>{{ __('Auto renewals that did not happen') }}</x-ui.heading>
                <ul class="mt-3 grid gap-1 text-sm">
                    @foreach ($attention['skipped'] as $skipped)
                        <li wire:key="skipped-{{ $skipped['username'] }}">
                            @include('partials.dashboard-customer', ['row' => $skipped])
                            <span class="text-zinc-600 dark:text-zinc-400">{{ $skipped['reason'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif

        @if ($attention['overLimit'] !== [])
            <x-ui.card>
                <x-ui.heading>{{ __('Over the monthly data limit') }}</x-ui.heading>
                <ul class="mt-3 grid gap-1 text-sm">
                    @foreach ($attention['overLimit'] as $over)
                        <li class="flex justify-between gap-4" wire:key="over-{{ $over['username'] }}">
                            @include('partials.dashboard-customer', ['row' => $over])
                            <span class="tabular-nums">{{ __(':used of :limit this month', ['used' => $over['used'], 'limit' => $over['limit']]) }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif

        @if ($attention['ranges'] !== [])
            <x-ui.card>
                <x-ui.heading>{{ __('IP ranges almost full') }}</x-ui.heading>
                <ul class="mt-3 grid gap-1 text-sm">
                    @foreach ($attention['ranges'] as $range)
                        <li class="flex justify-between gap-4" wire:key="range-{{ $loop->index }}">
                            <span class="font-medium">{{ $range['range'] }}</span>
                            <span class="tabular-nums">{{ __(':used of :total given out', ['used' => $range['used'], 'total' => $range['total']]) }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif

        @if ($attention['server'] !== null)
            <x-ui.card>
                <x-ui.heading>{{ __('Server') }}</x-ui.heading>
                <dl class="mt-3 grid gap-1 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt>{{ __('Last backup') }}</dt>
                        <dd @class(['tabular-nums', 'font-medium text-red-700 dark:text-red-400' => $attention['server']['backup_late']])>
                            {{ $attention['server']['backup_age_hours'] === null ? __('Never') : trans_choice(':count hour ago|:count hours ago', $attention['server']['backup_age_hours']) }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt>{{ __('Queue jobs waiting') }}</dt>
                        <dd class="tabular-nums">{{ number_format($attention['server']['jobs_waiting']) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt>{{ __('Queue jobs failed') }}</dt>
                        <dd @class(['tabular-nums', 'font-medium text-red-700 dark:text-red-400' => $attention['server']['jobs_failed'] > 0])>{{ number_format($attention['server']['jobs_failed']) }}</dd>
                    </div>
                </dl>
            </x-ui.card>
        @endif
    </div>

    <x-ui.card>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <x-ui.heading>{{ __('Latest refused logins') }}</x-ui.heading>
            @can('view-login-log')
                <x-ui.link href="{{ route('login-log.index', ['result' => 'rejected']) }}" wire:navigate class="text-sm">{{ __('Open the login log') }}</x-ui.link>
            @endcan
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
