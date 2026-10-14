<section class="grid gap-6">
    <div>
        <x-ui.heading level="1" size="xl">{{ __('FreeRADIUS settings') }}</x-ui.heading>
        <x-ui.text muted class="mt-1">{{ __('How FreeRADIUS treats expired customers, long sessions and failed logins. Saving restarts FreeRADIUS: logins in those 1 to 2 seconds fail and the routers retry.') }}</x-ui.text>
    </div>

    @if ($errors->any())
        <x-ui.alert variant="danger">{{ __('Nothing was saved. Fix the highlighted fields and save again.') }}</x-ui.alert>
    @endif

    <form wire:submit="save" class="grid gap-6">
        <div class="grid items-start gap-6 xl:grid-cols-2">
            <x-ui.card class="grid gap-5">
                <x-ui.heading>{{ __('Expired and disabled customers') }}</x-ui.heading>
                <x-ui.select wire:model="expire_type" :label="__('When an account expires')" :options="$this->expireTypes" />
                <x-ui.input
                    wire:model.live.blur="disabled_users_pool"
                    :label="__('Disabled pool')"
                    :description="__('The IP pool for inactive, suspended and expired customers. Every router needs a pool with this name.')"
                    maxlength="64"
                />
                @if ($this->poolIsMissing)
                    <x-ui.alert variant="warning">{{ __('No pool is named :name in Network > Pools. Add it there and on every router.', ['name' => $disabled_users_pool]) }}</x-ui.alert>
                @endif
                <x-ui.input wire:model="reject_session_timeout" type="number" min="60" max="65535" :label="__('Session length in the disabled pool (seconds)')" :description="__('After this the customer reconnects, so a recharge takes effect without a disconnect.')" />
            </x-ui.card>

            <x-ui.card class="grid gap-5">
                <x-ui.heading>{{ __('Sessions') }}</x-ui.heading>
                <x-ui.input wire:model="default_session_timeout" type="number" min="300" max="604800" :label="__('Longest session (seconds)')" :description="__('Every session ends after this and reconnects, e.g. 64800 for 18 hours.')" />
            </x-ui.card>

            <x-ui.card class="grid gap-5">
                <x-ui.heading>{{ __('Failed logins') }}</x-ui.heading>
                <x-ui.switch wire:model="count_and_reject" :label="__('Block repeated failed logins')" :description="__('A device that keeps failing is refused for a while. IPoE logins are never counted.')" />
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.input wire:model="reject_requests" type="number" min="1" max="1000" :label="__('Failed logins allowed')" />
                    <x-ui.input wire:model="reject_packet_interval" type="number" min="1" max="1440" :label="__('Within (minutes)')" />
                </div>
            </x-ui.card>

        </div>

        <x-ui.form-actions>
            <x-ui.button type="submit">{{ __('Save and restart FreeRADIUS') }}</x-ui.button>
        </x-ui.form-actions>
    </form>
</section>
