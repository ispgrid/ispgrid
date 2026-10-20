<section class="grid gap-6">
    <div>
        <x-ui.link href="{{ route('notification-rules.index') }}" wire:navigate class="text-sm">{{ __('Notification rules') }}</x-ui.link>
        <x-ui.heading level="1" size="xl" class="mt-2">{{ $ruleId === null ? __('Add rule') : $name }}</x-ui.heading>
    </div>

    @if ($this->providerOptions === [])
        <x-ui.alert variant="danger">{{ __('Switch a provider on under Providers before adding a rule.') }}</x-ui.alert>
    @endif

    <form wire:submit="save" class="grid gap-6">
        <div class="grid items-start gap-6 xl:grid-cols-2">
            <div class="grid content-start gap-6">
                <x-ui.card class="grid content-start gap-5">
                    <x-ui.heading>{{ __('When') }}</x-ui.heading>
                    <x-ui.input wire:model="name" :label="__('Name')" maxlength="100" required />
                    <x-ui.select wire:model.live="trigger" :label="__('Send on')" :options="$this->triggerOptions" />
                    @foreach ($this->triggerFields as $field)
                        <x-ui.input wire:model.live.debounce.500ms="triggerSettings.{{ $field->name }}" type="number" :min="$field->min" :max="$field->max" :label="$field->label" :description="$field->help" wire:key="trigger-{{ $trigger }}-{{ $field->name }}" />
                    @endforeach
                </x-ui.card>

                <x-ui.card class="grid content-start gap-5">
                    <x-ui.heading>{{ __('Template') }}</x-ui.heading>
                    <x-ui.select wire:model.live="provider" :label="__('Provider')" :options="$this->providerOptions" />
                    @foreach ($this->templateFields as $field)
                        <x-ui.input wire:model="template.{{ $field->name }}" :label="$field->label" :description="$field->help" :maxlength="$field->maxLength" wire:key="template-{{ $provider }}-{{ $field->name }}" />
                    @endforeach

                    <div class="grid gap-3">
                        <x-ui.text>{{ __('Values in order: value 1 fills the first variable of the template, and so on.') }}</x-ui.text>
                        @foreach ($values as $index => $value)
                            <div class="flex items-end gap-2" wire:key="value-{{ $index }}">
                                <div class="grow">
                                    <x-ui.select wire:model.live="values.{{ $index }}" :label="__('Value :number', ['number' => $index + 1])" :options="$this->placeholderOptions" />
                                </div>
                                <x-ui.button variant="ghost" wire:click="removeValue({{ $index }})">{{ __('Remove') }}</x-ui.button>
                            </div>
                        @endforeach
                        @error('values')
                            <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                        <div>
                            <x-ui.button variant="secondary" wire:click="addValue">{{ __('Add value') }}</x-ui.button>
                        </div>
                    </div>
                </x-ui.card>
            </div>

            <div class="grid content-start gap-6">
                <x-ui.card class="grid content-start gap-5">
                    <div>
                        <x-ui.heading>{{ __('Who') }}</x-ui.heading>
                        <x-ui.text muted class="mt-1">{{ __('Leave a group empty to include everyone.') }}</x-ui.text>
                    </div>
                    <fieldset class="grid gap-2">
                        <legend class="mb-1 text-sm font-medium">{{ __('Plans') }}</legend>
                        @foreach ($this->planOptions as $id => $label)
                            <x-ui.checkbox wire:model="plans" value="{{ $id }}" id="plan-{{ $id }}" :label="$label" />
                        @endforeach
                    </fieldset>
                    <fieldset class="grid gap-2 sm:grid-cols-2">
                        <legend class="mb-1 text-sm font-medium">{{ __('Service type') }}</legend>
                        @foreach ($this->serviceTypeOptions as $value => $label)
                            <x-ui.checkbox wire:model="serviceTypes" value="{{ $value }}" id="type-{{ $value }}" :label="$label" />
                        @endforeach
                    </fieldset>
                    <fieldset class="grid gap-2 sm:grid-cols-2">
                        <legend class="mb-1 text-sm font-medium">{{ __('Status') }}</legend>
                        @foreach ($this->statusOptions as $value => $label)
                            <x-ui.checkbox wire:model="statuses" value="{{ $value }}" id="status-{{ $value }}" :label="$label" />
                        @endforeach
                    </fieldset>
                </x-ui.card>

                <x-ui.card class="grid content-start gap-5">
                    <x-ui.heading>{{ __('Preview') }}</x-ui.heading>
                    <x-ui.textarea wire:model.live.debounce.500ms="approved_text" :label="__('Approved text (optional)')" rows="4" :description="__('Paste the approved template text with {1}, {2} where the values go. Only for this preview and the log; the provider sends its own approved text.')" />
                    <pre class="rounded-md bg-zinc-100 p-3 text-sm whitespace-pre-wrap dark:bg-zinc-800">{{ $this->preview }}</pre>

                    @if ($ruleId !== null)
                        <div class="grid gap-1.5">
                            <label for="test-number" class="text-sm font-medium">{{ __('Send a test to') }}</label>
                            <div class="flex items-start gap-2">
                                <div class="grow">
                                    <x-ui.input wire:model="testNumber" id="test-number" type="tel" inputmode="numeric" aria-describedby="test-number-help" />
                                </div>
                                <x-ui.button variant="secondary" wire:click="sendTest" class="shrink-0">{{ __('Send test') }}</x-ui.button>
                            </div>
                            <p id="test-number-help" class="text-xs text-zinc-600 dark:text-zinc-400">{{ __('Sends the saved rule now, with values from a real customer.') }}</p>
                        </div>
                    @endif
                </x-ui.card>
            </div>
        </div>

        <x-ui.form-actions>
            <x-ui.button type="submit">{{ __('Save rule') }}</x-ui.button>
            <x-ui.button variant="secondary" :href="route('notification-rules.index')" wire:navigate>{{ __('Cancel') }}</x-ui.button>
        </x-ui.form-actions>
    </form>
</section>
