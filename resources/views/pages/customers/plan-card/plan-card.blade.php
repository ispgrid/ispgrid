<div>
    <x-ui.card>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <x-ui.heading>
                {{ __('Plan') }}: {{ $this->details['plan'] ?? __('None') }}
                @if ($this->details['price'])
                    <span class="text-sm font-normal text-zinc-600 dark:text-zinc-400">{{ $this->details['price'] }}</span>
                @endif
            </x-ui.heading>
            @if ($this->details['online'])
                <x-ui.badge color="green">{{ __('Online') }}</x-ui.badge>
            @endif
        </div>

        <dl class="mt-4 grid gap-3 text-sm">
            <div class="grid grid-cols-[9rem_1fr] gap-3">
                <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Started') }}</dt>
                <dd>{{ $this->details['started'] ?? __('Not recharged yet') }}</dd>
            </div>
            <div class="grid grid-cols-[9rem_1fr] gap-3">
                <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Expires') }}</dt>
                <dd>
                    @if ($this->details['expires'])
                        <span @class(['text-red-700 dark:text-red-400' => $this->details['expired']])>{{ $this->details['expires'] }}</span>
                        <span class="text-zinc-600 dark:text-zinc-400">({{ $this->details['expires_in'] }})</span>
                    @else
                        {{ __('Never') }}
                    @endif
                </dd>
            </div>
            <div class="grid grid-cols-[9rem_1fr] gap-3">
                <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Auto renew') }}</dt>
                <dd>
                    <x-ui.badge :color="$this->details['auto_renew'] ? 'green' : 'zinc'">{{ $this->details['auto_renew'] ? __('On') : __('Off') }}</x-ui.badge>
                    @if ($this->details['renews'])
                        <span class="text-zinc-600 dark:text-zinc-400">{{ $this->details['renews'] }}</span>
                    @endif
                </dd>
            </div>
            <div class="grid grid-cols-[9rem_1fr] gap-3">
                <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Advance renewal') }}</dt>
                <dd>
                    @if ($this->details['waiting'])
                        <x-ui.badge color="amber">{{ __('Waiting') }}</x-ui.badge>
                        {{ __(':plan, :from to :to', ['plan' => $this->details['waiting']['plan'], 'from' => $this->details['waiting']['starts'], 'to' => $this->details['waiting']['expiry']]) }}
                        <span class="text-zinc-600 dark:text-zinc-400">({{ $this->details['waiting']['amount'] }})</span>
                        @if ($this->buttons['change_plan'])
                            <x-ui.button variant="ghost" size="sm" wire:click="cancelRenewal" wire:confirm="{{ __('Cancel the advance renewal?') }}">{{ __('Cancel') }}</x-ui.button>
                        @endif
                    @else
                        {{ __('None') }}
                    @endif
                </dd>
            </div>
            <div class="grid grid-cols-[9rem_1fr] gap-3">
                <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Speed') }}</dt>
                <dd>
                    {{ $this->details['speed'] }}
                    @if ($this->details['own_speed'])
                        <x-ui.badge color="amber">{{ $this->details['own_speed'] }}</x-ui.badge>
                    @endif
                </dd>
            </div>
            @if ($this->details['data'])
                <div class="grid grid-cols-[9rem_1fr] gap-3">
                    <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Data') }}</dt>
                    <dd>{{ $this->details['data'] }}</dd>
                </div>
            @endif
            @if ($this->details['dues'])
                <div class="grid grid-cols-[9rem_1fr] gap-3">
                    <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Dues') }}</dt>
                    <dd class="font-semibold tabular-nums text-red-700 dark:text-red-300">{{ $this->details['dues'] }}</dd>
                </div>
            @endif
        </dl>

        @if (in_array(true, $this->buttons, true))
            <div class="mt-4 flex flex-wrap gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                @if ($this->buttons['recharge'])
                    <x-ui.button size="sm" x-on:click="$dispatch('open-recharge')">{{ __('Recharge') }}</x-ui.button>
                @endif
                @if ($this->buttons['change_plan'])
                    <x-ui.button variant="secondary" size="sm" wire:click="openChangePlan">{{ __('Change plan') }}</x-ui.button>
                @endif
                @if ($this->buttons['advance'])
                    <x-ui.button variant="secondary" size="sm" wire:click="openAdvance">{{ __('Renew in advance') }}</x-ui.button>
                @endif
                @if ($this->buttons['change_expiry'])
                    <x-ui.button variant="secondary" size="sm" wire:click="openChangeExpiry">{{ __('Change expiry') }}</x-ui.button>
                @endif
                @if ($this->buttons['own_speed'])
                    <x-ui.button variant="secondary" size="sm" wire:click="openOverrideSpeed">{{ __('Override speed') }}</x-ui.button>
                @endif
                @if ($this->buttons['terminate'])
                    <x-ui.button variant="ghost" size="sm" class="text-red-600 dark:text-red-400" wire:click="openTerminate">{{ __('Terminate') }}</x-ui.button>
                @endif
            </div>
        @endif
    </x-ui.card>

    @if ($this->buttons['change_plan'])
        <x-ui.modal name="change-plan">
            <form wire:submit="changePlan" class="grid gap-5" novalidate>
                <x-ui.heading size="lg">{{ __('Change plan') }}</x-ui.heading>
                <x-ui.text muted>{{ __('Starts now. The time left on the current plan ends, and an online customer is disconnected so the new plan applies at once.') }}</x-ui.text>
                <x-ui.select wire:model.live="change_plan_id" :label="__('Plan')" :options="$this->plans" :placeholder="__('Choose a plan')" />
                @include('partials.billing-breakdown', ['charge' => $this->changeCharge, 'amountField' => 'change_amount', 'idPrefix' => 'change', 'amountDescription' => __('The plan price less the customer discount. Change it for a part payment.')])
                <x-ui.input wire:model="change_expiry" type="datetime-local" :label="__('Expires')" :description="__('From now, with the plan validity and the expiry mode in App settings. You can change it.')" />
                <x-ui.input wire:model="change_note" :label="__('Comment')" maxlength="255" />
                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" x-on:click="$dispatch('modal-close', { name: 'change-plan' })">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit">{{ __('Change plan') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>

        <x-ui.modal name="renew-advance">
            <form wire:submit="bookRenewal" class="grid gap-5" novalidate>
                <x-ui.heading size="lg">{{ __('Renew in advance') }}</x-ui.heading>
                <x-ui.text muted>{{ __('Starts when the current plan ends, on :date. Until then nothing changes for the customer.', ['date' => $this->details['expires'] ?? '']) }}</x-ui.text>
                <x-ui.select wire:model.live="advance_plan_id" :label="__('Next plan')" :options="$this->plans" :placeholder="__('Choose a plan')" />
                @include('partials.billing-breakdown', ['charge' => $this->advanceCharge, 'amountField' => 'advance_amount', 'idPrefix' => 'advance', 'amountDescription' => null])
                <x-ui.input wire:model="advance_expiry" type="datetime-local" :label="__('Expires')" />
                <x-ui.input wire:model="advance_note" :label="__('Comment')" maxlength="255" />
                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" x-on:click="$dispatch('modal-close', { name: 'renew-advance' })">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit">{{ __('Renew in advance') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>

        <x-ui.modal name="change-expiry" width="sm">
            <form wire:submit="changeExpiry" class="grid gap-5" novalidate>
                <x-ui.heading size="lg">{{ __('Change expiry') }}</x-ui.heading>
                <x-ui.input wire:model="new_expiry" type="datetime-local" :label="__('Expires')" />
                <x-ui.input wire:model="expiry_note" :label="__('Comment')" maxlength="255" />
                <x-ui.text muted>{{ __('No money is recorded. A waiting advance renewal moves with the new date.') }}</x-ui.text>
                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" x-on:click="$dispatch('modal-close', { name: 'change-expiry' })">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit">{{ __('Change expiry') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    @if ($this->buttons['own_speed'])
        <x-ui.modal name="override-speed" width="sm">
            <form wire:submit="saveOverrideSpeed" class="grid gap-5" novalidate>
                <x-ui.heading size="lg">{{ __('Override speed') }}</x-ui.heading>
                <x-ui.text muted>{{ __('Speeds are a whole number with k or M, e.g. 512k or 25M. Upload is what the customer sends.') }}</x-ui.text>
                <div class="grid grid-cols-2 gap-3">
                    <x-ui.input wire:model="speed_upload" english :label="__('Upload')" autocomplete="off" />
                    <x-ui.input wire:model="speed_download" english :label="__('Download')" autocomplete="off" />
                </div>
                <x-ui.input wire:model="speed_until" type="datetime-local" :label="__('Until')" :description="__('Optional. The plan speed comes back by itself at this time.')" />
                <x-ui.input wire:model="speed_note" :label="__('Comment')" maxlength="255" />
                <div class="flex flex-wrap justify-end gap-2">
                    @if ($this->details['own_speed'])
                        <x-ui.button variant="ghost" wire:click="removeOverrideSpeed">{{ __('Remove override speed') }}</x-ui.button>
                    @endif
                    <x-ui.button variant="secondary" x-on:click="$dispatch('modal-close', { name: 'override-speed' })">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    @if ($this->buttons['terminate'])
        <x-ui.modal name="terminate" width="sm">
            <form wire:submit="terminate" class="grid gap-5" novalidate>
                <x-ui.heading size="lg">{{ __('Terminate') }}</x-ui.heading>
                <x-ui.text>{{ __('Removes the plan and sets the customer to Terminated. They can no longer log in and are disconnected now. Auto renew and a waiting renewal are cancelled.') }}</x-ui.text>
                <x-ui.textarea wire:model="terminate_note" :label="__('Comment')" :description="__('Why the customer is terminated. It is kept in the activity log.')" rows="2" maxlength="500" required />
                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" x-on:click="$dispatch('modal-close', { name: 'terminate' })">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button variant="danger" type="submit">{{ __('Terminate') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
</div>
