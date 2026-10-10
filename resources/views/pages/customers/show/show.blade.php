<section class="grid max-w-4xl gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <x-ui.link href="{{ route('customers.index') }}" wire:navigate class="text-sm">{{ __('Customers') }}</x-ui.link>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <x-ui.heading level="1" size="xl">{{ $this->account->username }}</x-ui.heading>
                <x-ui.badge :color="$this->account->status_id->color()">{{ $this->account->status_id->label() }}</x-ui.badge>
                @if ($this->account->trashed())
                    <x-ui.badge>{{ __('Deleted') }}</x-ui.badge>
                @endif
            </div>
            <x-ui.text muted class="mt-1">{{ $this->account->fullname }}</x-ui.text>
        </div>

        <div class="flex flex-wrap gap-2">
            @if ($this->account->trashed())
                @can('restore', $this->account)
                    <x-ui.button variant="secondary" icon="arrow-uturn-left" wire:click="restore">{{ __('Restore') }}</x-ui.button>
                @endcan
            @else
                @can('update', $this->account)
                    <x-ui.button wire:click="openRecharge">{{ __('Recharge') }}</x-ui.button>
                    <x-ui.button variant="secondary" :href="route('customers.edit', $this->account->id)" wire:navigate>{{ __('Edit') }}</x-ui.button>
                    <x-ui.button variant="secondary" x-on:click="$dispatch('modal-open', { name: 'change-status' })">{{ __('Change status') }}</x-ui.button>
                @endcan
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

    <div class="grid gap-6 md:grid-cols-2">
        <x-ui.card>
            <x-ui.heading>{{ __('Service') }}</x-ui.heading>
            <dl class="mt-4 grid gap-3 text-sm">
                @foreach ($this->service as $label => $value)
                    <div class="grid grid-cols-[9rem_1fr] gap-3">
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
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
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
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
                <div><dt class="text-zinc-500 dark:text-zinc-400">{{ __('IP address') }}</dt><dd class="font-mono">{{ $this->technician['ip'] }}</dd></div>
                <div><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Mask') }}</dt><dd class="font-mono">{{ $this->technician['mask'] }}</dd></div>
                <div><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Gateway') }}</dt><dd class="font-mono">{{ $this->technician['gateway'] }}</dd></div>
                <div><dt class="text-zinc-500 dark:text-zinc-400">{{ __('DNS') }}</dt><dd class="font-mono">{{ $this->technician['dns'] }}</dd></div>
            </dl>
        </x-ui.card>
    @endif

    @unless ($this->account->trashed())
        <x-ui.card>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <x-ui.heading>{{ __('Bound devices') }}</x-ui.heading>
                    <x-ui.text muted class="mt-1">{{ __('FreeRADIUS binds the devices a customer logs in from. Reset them when the customer changes router or device.') }}</x-ui.text>
                </div>
                @can('update', $this->account)
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
                    @can('update', $this->account)
                        <x-ui.button variant="secondary" icon="lock-closed" wire:click="unlock">{{ __('Unlock') }}</x-ui.button>
                    @endcan
                </div>
            </x-ui.card>
        @endif
    @endunless

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

    <x-ui.modal name="recharge">
        <form wire:submit="recharge" class="grid gap-5" novalidate>
            <x-ui.heading size="lg">{{ __('Recharge') }}</x-ui.heading>

            @if (in_array($this->account->status_id, [\App\Enums\AccountStatus::Suspended, \App\Enums\AccountStatus::Terminated], true))
                <x-ui.alert variant="warning">{{ __('A recharge keeps the status :status. Change the status separately.', ['status' => $this->account->status_id->label()]) }}</x-ui.alert>
            @endif

            <x-ui.select wire:model.live="recharge_plan_id" :label="__('Plan')" :options="$this->rechargePlans" :placeholder="__('Choose a plan')" />
            <x-ui.input wire:model="recharge_expiry" type="datetime-local" :label="__('Expiry')" :description="__('From the plan validity and the expiry mode in App settings. You can change it.')" />
            <x-ui.input wire:model="recharge_amount" inputmode="decimal" :label="__('Amount')" :description="__('The plan price. Change it for a discount or a part payment.')" />
            <x-ui.input wire:model="recharge_note" :label="__('Note')" maxlength="255" />

            <div class="flex justify-end gap-2">
                <x-ui.button variant="secondary" x-on:click="$dispatch('modal-close', { name: 'recharge' })">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Recharge') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</section>
