<section class="grid gap-6">
    <div>
        <x-ui.link href="{{ route('roles.index') }}" wire:navigate class="text-sm">{{ __('Roles') }}</x-ui.link>
        <x-ui.heading level="1" size="xl" class="mt-2">{{ $roleId ? __('Edit role') : __('Add role') }}</x-ui.heading>
        <x-ui.text muted class="mt-1">{{ __('Tick what staff with this role may see and do. Ticking an action also ticks the view box of its area. Changes apply at once.') }}</x-ui.text>
    </div>

    <form wire:submit="save" novalidate class="grid gap-6">
        <x-ui.card>
            <div class="grid items-start gap-5 sm:grid-cols-2 xl:grid-cols-4">
                <x-ui.input wire:model="name" :label="__('Name')" maxlength="50" required autocomplete="off" />
            </div>
            @error('permissions')
                <p class="mt-3 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </x-ui.card>

        <div class="grid items-start gap-6 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->areas as $area => $boxes)
                <x-ui.card wire:key="area-{{ $loop->index }}">
                    <fieldset class="grid gap-3">
                        <legend class="mb-1 font-semibold">{{ $area }}</legend>
                        @foreach ($boxes as $box)
                            <x-ui.checkbox wire:model.live="permissions" value="{{ $box['value'] }}" id="permission-{{ $box['value'] }}" :label="$box['label']" />
                        @endforeach
                    </fieldset>
                </x-ui.card>
            @endforeach
        </div>

        <x-ui.form-actions>
            <x-ui.button type="submit">{{ __('Save role') }}</x-ui.button>
            <x-ui.button variant="secondary" :href="route('roles.index')" wire:navigate>{{ __('Cancel') }}</x-ui.button>
        </x-ui.form-actions>
    </form>
</section>
