<section class="grid gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <x-ui.link href="{{ route('customers.index') }}" wire:navigate class="text-sm">{{ __('Customers') }}</x-ui.link>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <x-ui.heading level="1" size="xl">{{ $this->heading['name'] }}</x-ui.heading>
                <x-ui.badge :color="$this->account->status_id->color()">{{ $this->account->status_id->label() }}</x-ui.badge>
                @if ($this->account->trashed())
                    <x-ui.badge>{{ __('Deleted') }}</x-ui.badge>
                @endif
            </div>
            @if ($this->heading['login'] !== null)
                <x-ui.text muted class="mt-1">{{ $this->heading['login'] }}</x-ui.text>
            @endif
        </div>

        <div class="flex flex-wrap gap-2">
            @if ($this->account->trashed())
                @can('restore', $this->account)
                    <x-ui.button variant="secondary" icon="arrow-uturn-left" wire:click="restore">{{ __('Restore') }}</x-ui.button>
                @endcan
            @else
                @can('changeStatus', $this->account)
                    <x-ui.button variant="secondary" x-on:click="$dispatch('modal-open', { name: 'change-status' })">{{ __('Change status') }}</x-ui.button>
                @endcan
                @if ($this->sessions !== [])
                    @can('disconnect-sessions')
                        <x-ui.button variant="secondary" icon="x-circle" wire:click="disconnect" wire:confirm="{{ __('Disconnect every session of :name?', ['name' => $this->account->username]) }}">{{ __('Disconnect') }}</x-ui.button>
                    @endcan
                @endif
                @can('delete', $this->account)
                    <x-ui.button
                        variant="ghost"
                        icon="trash"
                        class="text-red-600 dark:text-red-400"
                        wire:click="delete"
                        wire:confirm="{{ __('Delete :name? Their IP goes back to the inventory, their pool and devices are removed, and they can no longer log in. To close an account, set its status to Terminated instead.', ['name' => $this->account->username]) }}"
                    >{{ __('Delete') }}</x-ui.button>
                @endcan
            @endif
        </div>
    </div>

    @if ($this->account->trashed())
        <x-ui.alert variant="warning">{{ __('This customer is deleted and cannot log in. Restore brings them back as Inactive, with the default pool or IP of their type.') }}</x-ui.alert>
    @endif

    @if ($this->boundElsewhere)
        <x-ui.alert variant="warning">
            {{ __('The last login was refused: device :mac is bound to :username.', ['mac' => $this->boundElsewhere['mac'], 'username' => $this->boundElsewhere['username']]) }}
            <x-ui.link href="{{ route('customers.show', $this->boundElsewhere['id']) }}" wire:navigate>{{ __('Reset the devices of :username', ['username' => $this->boundElsewhere['username']]) }}</x-ui.link>
        </x-ui.alert>
    @endif

    @if ($this->disconnectFailure)
        <x-ui.alert variant="danger">
            {{ __('The last disconnect failed (:when): :reason. The customer may still be online with the old settings.', $this->disconnectFailure) }}
        </x-ui.alert>
    @endif

    <x-ui.nav-tabs :tabs="$this->tabs" :active="$this->activeTab">
        <x-slot:actions>
            @unless ($this->account->trashed())
                @can('update', $this->account)
                    <x-ui.button variant="secondary" size="sm" :href="route('customers.edit', $this->account->id)" wire:navigate>{{ __('Edit') }}</x-ui.button>
                @endcan
            @endunless
        </x-slot:actions>
    </x-ui.nav-tabs>

    @if ($this->activeTab === 'details')
        <livewire:pages::customers.plan-card :account-id="$this->account->id" :key="'plan-card-'.$this->account->id" />

        <div class="grid gap-6 md:grid-cols-2">
            <x-ui.card>
                <x-ui.heading>{{ __('Service') }}</x-ui.heading>
                <dl class="mt-4 grid gap-3 text-sm">
                    @foreach ($this->service as $label => $value)
                        <div class="grid grid-cols-[9rem_1fr] gap-3">
                            <dt class="text-zinc-600 dark:text-zinc-400">{{ $label }}</dt>
                            <dd class="break-words">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-ui.card>

            <x-ui.card>
                <x-ui.heading>{{ __('Profile') }}</x-ui.heading>
                <dl class="mt-4 grid gap-3 text-sm">
                    @foreach ($this->profile as $label => $value)
                        <div class="grid grid-cols-[9rem_1fr] gap-3">
                            <dt class="text-zinc-600 dark:text-zinc-400">{{ $label }}</dt>
                            <dd class="break-words whitespace-pre-line">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-ui.card>
        </div>

        @if ($this->technician)
            <x-ui.card>
                <x-ui.heading>{{ __('For the technician') }}</x-ui.heading>
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-4">
                    <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('IP address') }}</dt><dd class="font-mono">{{ $this->technician['ip'] }}</dd></div>
                    <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Mask') }}</dt><dd class="font-mono">{{ $this->technician['mask'] }}</dd></div>
                    <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Gateway') }}</dt><dd class="font-mono">{{ $this->technician['gateway'] }}</dd></div>
                    <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('DNS') }}</dt><dd class="font-mono">{{ $this->technician['dns'] }}</dd></div>
                </dl>
            </x-ui.card>
        @endif

        @foreach ($this->addonCards as $card)
            <livewire:dynamic-component :is="$card" :account-id="$this->account->id" :key="'addon-card-'.$card" />
        @endforeach
    @endif

    @if ($this->activeTab === 'connection')
        <x-ui.card>
            <x-ui.heading>{{ __('Online') }}</x-ui.heading>
            @if ($this->sessions === [])
                <x-ui.text muted class="mt-4">{{ __('Not online.') }}</x-ui.text>
            @else
                <x-ui.table class="mt-4">
                    <x-slot:head>
                        <x-ui.th>{{ __('NAS') }}</x-ui.th>
                        <x-ui.th>{{ __('IP address') }}</x-ui.th>
                        <x-ui.th>{{ __('Device (MAC)') }}</x-ui.th>
                        <x-ui.th>{{ __('Started') }}</x-ui.th>
                        <x-ui.th>{{ __('Duration') }}</x-ui.th>
                        <x-ui.th>{{ __('Download') }}</x-ui.th>
                        <x-ui.th>{{ __('Upload') }}</x-ui.th>
                        <x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
                    </x-slot:head>
                    @foreach ($this->sessions as $session)
                        <tr wire:key="session-{{ $session['id'] }}">
                            <x-ui.td>{{ $session['router'] }}</x-ui.td>
                            <x-ui.td class="font-mono">{{ $session['ip'] }}</x-ui.td>
                            <x-ui.td class="font-mono">{{ $session['mac'] }}</x-ui.td>
                            <x-ui.td class="tabular-nums whitespace-nowrap">{{ $session['started'] }}</x-ui.td>
                            <x-ui.td class="tabular-nums whitespace-nowrap">{{ $session['duration'] }}</x-ui.td>
                            <x-ui.td class="tabular-nums whitespace-nowrap">{{ $session['download'] }}</x-ui.td>
                            <x-ui.td class="tabular-nums whitespace-nowrap">{{ $session['upload'] }}</x-ui.td>
                            <x-ui.td class="text-end">
                                @can('disconnect-sessions')
                                    <x-ui.button variant="ghost" icon="x-circle" wire:click="disconnect({{ $session['id'] }})">{{ __('Disconnect') }}</x-ui.button>
                                @endcan
                            </x-ui.td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>

        <x-ui.card>
            <x-ui.heading>{{ __('Usage') }}</x-ui.heading>
            <x-ui.text muted class="mt-1">{{ __('Sessions that started in each period, as FreeRADIUS counts them for the limits.') }}</x-ui.text>
            <x-ui.table class="mt-4">
                <x-slot:head>
                    <x-ui.th>{{ __('Period') }}</x-ui.th>
                    <x-ui.th>{{ __('Data') }}</x-ui.th>
                    <x-ui.th>{{ __('Time') }}</x-ui.th>
                </x-slot:head>
                @foreach ($this->usage as $period)
                    <tr wire:key="usage-{{ $loop->index }}">
                        <x-ui.td>{{ $period['period'] }}</x-ui.td>
                        <x-ui.td class="tabular-nums">{{ $period['data'] }}</x-ui.td>
                        <x-ui.td class="tabular-nums">{{ $period['time'] }}</x-ui.td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>

        @unless ($this->account->trashed())
            <x-ui.card>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <x-ui.heading>{{ __('Bound devices') }}</x-ui.heading>
                        <x-ui.text muted class="mt-1">{{ __('FreeRADIUS binds the devices a customer logs in from. Reset them when the customer changes router or device.') }}</x-ui.text>
                    </div>
                    @can('resetDevices', $this->account)
                        <x-ui.button variant="secondary" icon="arrow-path" wire:click="resetDevices" wire:confirm="{{ __('Forget every device bound to this customer?') }}">{{ __('Reset devices') }}</x-ui.button>
                    @endcan
                </div>

                @if ($this->devices === [])
                    <x-ui.text muted class="mt-4">{{ __('No device is bound yet. The next one to log in is bound.') }}</x-ui.text>
                @else
                    <x-ui.table class="mt-4">
                        <x-slot:head>
                            <x-ui.th>{{ __('Device (MAC)') }}</x-ui.th>
                            <x-ui.th>{{ __('Bound') }}</x-ui.th>
                            <x-ui.th>{{ __('Last seen') }}</x-ui.th>
                        </x-slot:head>
                        @foreach ($this->devices as $device)
                            <tr wire:key="device-{{ $device['mac'] }}">
                                <x-ui.td class="font-mono">{{ $device['mac'] }}</x-ui.td>
                                <x-ui.td class="tabular-nums">{{ $device['learned'] }}</x-ui.td>
                                <x-ui.td class="tabular-nums">{{ $device['seen'] ?? __('Not yet') }}</x-ui.td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>

            @if ($this->failedLogins)
                <x-ui.card>
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <x-ui.heading>{{ __('Failed logins') }}</x-ui.heading>
                            <x-ui.text muted class="mt-1">{{ trans_choice(':count failed login counted, the last at :time. Too many lock the customer out for a while.|:count failed logins counted, the last at :time. Too many lock the customer out for a while.', $this->failedLogins['count'], ['time' => $this->failedLogins['last']]) }}</x-ui.text>
                        </div>
                        @can('resetDevices', $this->account)
                            <x-ui.button variant="secondary" icon="lock-closed" wire:click="unlock">{{ __('Unlock') }}</x-ui.button>
                        @endcan
                    </div>
                </x-ui.card>
            @endif
        @endunless

        <x-ui.card>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <x-ui.heading>{{ __('Login attempts') }}</x-ui.heading>
                @can('view-login-log')
                    <x-ui.link href="{{ route('login-log.index', ['username' => $this->account->username]) }}" wire:navigate class="text-sm">{{ __('Open the login log') }}</x-ui.link>
                @endcan
            </div>
            @if ($this->logins === [])
                <x-ui.text muted class="mt-4">{{ __('No login attempt yet.') }}</x-ui.text>
            @else
                <x-ui.table class="mt-4">
                    <x-slot:head>
                        <x-ui.th>{{ __('When') }}</x-ui.th>
                        <x-ui.th>{{ __('Result') }}</x-ui.th>
                        <x-ui.th>{{ __('Reason') }}</x-ui.th>
                        <x-ui.th>{{ __('NAS') }}</x-ui.th>
                        <x-ui.th>{{ __('Device (MAC)') }}</x-ui.th>
                    </x-slot:head>
                    @foreach ($this->logins as $login)
                        <tr wire:key="login-{{ $login['id'] }}">
                            <x-ui.td class="tabular-nums whitespace-nowrap">{{ $login['when'] }}</x-ui.td>
                            <x-ui.td><x-ui.badge :color="$login['color']">{{ $login['result'] }}</x-ui.badge></x-ui.td>
                            <x-ui.td class="break-words">{{ $login['reason'] }}</x-ui.td>
                            <x-ui.td>{{ $login['router'] }}</x-ui.td>
                            <x-ui.td class="font-mono">{{ $login['mac'] }}</x-ui.td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>
    @endif

    @if ($this->activeTab === 'recharges')
        <x-ui.card>
            <x-ui.heading>{{ __('Recharges') }}</x-ui.heading>
            @if ($this->recharges === [])
                <x-ui.text muted class="mt-4">{{ __('No recharge yet.') }}</x-ui.text>
            @else
                <x-ui.table class="mt-4">
                    <x-slot:head>
                        <x-ui.th>{{ __('When') }}</x-ui.th>
                        <x-ui.th>{{ __('Plan') }}</x-ui.th>
                        <x-ui.th>{{ __('Expiry') }}</x-ui.th>
                        <x-ui.th class="text-end">{{ __('Amount') }}</x-ui.th>
                        <x-ui.th>{{ __('Invoice') }}</x-ui.th>
                        <x-ui.th>{{ __('Note') }}</x-ui.th>
                        <x-ui.th>{{ __('Staff') }}</x-ui.th>
                    </x-slot:head>
                    @foreach ($this->recharges as $recharge)
                        <tr wire:key="recharge-{{ $recharge['id'] }}">
                            <x-ui.td class="tabular-nums whitespace-nowrap">{{ $recharge['when'] }}</x-ui.td>
                            <x-ui.td>{{ $recharge['plan'] }}</x-ui.td>
                            <x-ui.td class="tabular-nums whitespace-nowrap">{{ $recharge['expiry'] }}</x-ui.td>
                            <x-ui.td class="text-end tabular-nums">{{ $recharge['amount'] }}</x-ui.td>
                            <x-ui.td class="whitespace-nowrap">
                                @if ($recharge['invoice'])
                                    @can('view-invoices')
                                        <x-ui.link href="{{ route('invoices.show', $recharge['invoice']['id']) }}" wire:navigate>{{ $recharge['invoice']['number'] }}</x-ui.link>
                                    @else
                                        {{ $recharge['invoice']['number'] }}
                                    @endcan
                                @endif
                            </x-ui.td>
                            <x-ui.td>{{ $recharge['note'] }}</x-ui.td>
                            <x-ui.td>{{ $recharge['staff'] }}</x-ui.td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>
    @endif

    @if ($this->activeTab === 'billing')
        <livewire:pages::customers.billing-tab :account-id="$this->account->id" :key="'billing-tab-'.$this->account->id" />
    @endif

    @if ($this->activeTab === 'messages')
        @can('view-notification-log')
            <x-ui.card>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <x-ui.heading>{{ __('Messages') }}</x-ui.heading>
                    <x-ui.link href="{{ route('notification-log.index', ['customer' => $this->account->username]) }}" wire:navigate class="text-sm">{{ __('All messages') }}</x-ui.link>
                </div>
                @if ($this->messages === [])
                    <x-ui.text muted class="mt-4">{{ __('No messages yet.') }}</x-ui.text>
                @else
                    <ul class="mt-4 grid gap-3 text-sm">
                        @foreach ($this->messages as $message)
                            <li class="flex flex-wrap items-start gap-2" wire:key="message-{{ $loop->index }}">
                                <span class="tabular-nums text-zinc-600 dark:text-zinc-400">{{ $message['when'] }}</span>
                                <x-ui.badge :color="$message['color']">{{ $message['status'] }}</x-ui.badge>
                                <span class="font-medium">{{ $message['rule'] }}</span>
                                <span class="basis-full break-words">{{ $message['text'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        @endcan
    @endif

    @if ($this->activeTab === 'activity')
        <x-ui.card>
            <x-ui.heading>{{ __('Activity') }}</x-ui.heading>

            <div class="mt-4 flex flex-wrap items-end gap-3">
                <div class="w-44">
                    <x-ui.select wire:model.live="activityPeriod" :label="__('Period')" :options="$this->activityPeriods" />
                </div>
                @if ($activityPeriod === 'range')
                    <div class="w-40">
                        <x-ui.input wire:model.live="activityFrom" type="date" :label="__('From')" />
                    </div>
                    <div class="w-40">
                        <x-ui.input wire:model.live="activityTo" type="date" :label="__('To')" />
                    </div>
                @endif
                <div class="w-52">
                    <x-ui.select wire:model.live="activityAction" :label="__('Action')" :options="$this->activityActions" :placeholder="__('All actions')" />
                </div>
            </div>

            @if ($this->timeline === [])
                <x-ui.text muted class="mt-4">{{ __('No activity yet.') }}</x-ui.text>
            @endif

            @foreach ($this->timeline as $day => $entries)
                <div wire:key="activity-day-{{ $day }}" class="mt-6">
                    <p class="text-sm font-semibold text-zinc-600 dark:text-zinc-400">{{ $day }}</p>
                    <ol class="relative mt-2 grid gap-2 pl-9 before:absolute before:top-0 before:bottom-0 before:left-3 before:w-0.5 before:bg-zinc-200 dark:before:bg-zinc-700">
                        @foreach ($entries as $entry)
                            <li wire:key="activity-{{ $entry['id'] }}" class="relative flex justify-between gap-3 rounded-lg border border-zinc-200 px-3.5 py-2.5 text-sm dark:border-zinc-700">
                                <span @class([
                                    'absolute top-2.5 -left-8 flex size-5.5 items-center justify-center rounded-full text-white',
                                    'bg-green-700' => $entry['tone'] === 'green',
                                    'bg-blue-600' => $entry['tone'] === 'blue',
                                    'bg-red-600' => $entry['tone'] === 'red',
                                    'bg-amber-600' => $entry['tone'] === 'amber',
                                    'bg-zinc-500' => $entry['tone'] === 'grey',
                                ])>
                                    <x-ui.icon :name="$entry['icon']" class="size-3.5" />
                                </span>
                                <div class="min-w-0">
                                    <p class="break-words">
                                        {{ $entry['sentence'] }}
                                        <span class="text-zinc-600 dark:text-zinc-400">{{ __('By :name.', ['name' => $entry['by']]) }}</span>
                                    </p>
                                    @if ($entry['note'])
                                        <p class="mt-1 text-zinc-600 dark:text-zinc-400">{{ $entry['note'] }}</p>
                                    @endif
                                    @if ($entry['changes'] !== [])
                                        <dl class="mt-1 flex flex-wrap gap-x-4 gap-y-0.5 text-xs">
                                            @foreach ($entry['changes'] as $change)
                                                <div class="flex flex-wrap gap-1">
                                                    <dt class="text-zinc-600 dark:text-zinc-400">{{ $change['field'] }}:</dt>
                                                    <dd>
                                                        @if ($change['old'] !== null)
                                                            <span class="line-through decoration-zinc-400">{{ $change['old'] }}</span>
                                                            <span aria-hidden="true">&rarr;</span>
                                                        @endif
                                                        <span>{{ $change['new'] ?? __('(empty)') }}</span>
                                                    </dd>
                                                </div>
                                            @endforeach
                                        </dl>
                                    @endif
                                </div>
                                <span class="whitespace-nowrap text-zinc-600 tabular-nums dark:text-zinc-400">{{ $entry['time'] }}</span>
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endforeach

            @if ($this->hasMoreActivity)
                <div class="mt-4">
                    <x-ui.button variant="secondary" size="sm" wire:click="showMoreActivity">{{ __('Show more') }}</x-ui.button>
                </div>
            @endif
        </x-ui.card>
    @endif

    @if ($this->addonTab)
        <livewire:dynamic-component :is="$this->addonTab" :account-id="$this->account->id" :key="'addon-tab-'.$this->activeTab" />
    @endif

    @can('changeStatus', $this->account)
        <x-ui.modal name="change-status" width="sm">
            <form wire:submit="changeStatus" class="grid gap-5">
                <x-ui.heading size="lg">{{ __('Change status') }}</x-ui.heading>
                <x-ui.select wire:model="status" :label="__('Status')" :options="$this->statuses" :description="__('Inactive and Suspended customers get the disabled pool (Hotspot logins are refused). Terminated customers are refused everywhere. Active customers without a plan still get the disabled pool: recharge them to give them one.')" />
                <x-ui.textarea wire:model="remark" :label="__('Remark')" :description="__('Why the status changes. It is kept in the activity log.')" rows="2" maxlength="500" required />
                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" x-on:click="$dispatch('modal-close', { name: 'change-status' })">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit">{{ __('Change status') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endcan

    @can('recharge', $this->account)
        <x-ui.modal name="recharge">
            <form wire:submit="recharge" class="grid gap-5" novalidate>
                <x-ui.heading size="lg">{{ __('Recharge') }}</x-ui.heading>

                @if ($this->rechargeKeepsStatus)
                    <x-ui.alert variant="warning">{{ __('A recharge keeps the status :status. Change the status separately.', ['status' => $this->rechargeKeepsStatus]) }}</x-ui.alert>
                @endif

                <x-ui.select wire:model.live="recharge_plan_id" :label="__('Plan')" :options="$this->rechargePlans" :placeholder="__('Choose a plan')" />
                @if ($this->rechargePending !== null)
                    <x-ui.text>{{ __('Starts at first login: :validity', ['validity' => $this->rechargePending]) }}</x-ui.text>
                @else
                    <x-ui.input wire:model="recharge_expiry" type="datetime-local" :label="__('Expiry')" :description="__('From the plan validity and the expiry mode in App settings. You can change it.')" />
                @endif
                @include('partials.billing-breakdown', ['charge' => $this->rechargeCharge, 'amountField' => 'recharge_amount', 'idPrefix' => 'recharge', 'amountDescription' => __('The plan price less the customer discount. Change it for a part payment.')])
                <x-ui.input wire:model="recharge_note" :label="__('Note')" maxlength="255" />

                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" x-on:click="$dispatch('modal-close', { name: 'recharge' })">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit">{{ __('Recharge') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endcan
</section>
