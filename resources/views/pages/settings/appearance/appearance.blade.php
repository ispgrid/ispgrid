<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Appearance')" :subheading="__('Update the appearance settings for your account')">
        <div class="inline-flex gap-1 rounded-lg border border-zinc-200 p-1 dark:border-zinc-700" role="radiogroup" aria-label="{{ __('Appearance') }}">
            @foreach ($this->modes as $value => $mode)
                <button
                    type="button"
                    role="radio"
                    x-on:click="$store.appearance.set(@js($value))"
                    x-bind:aria-checked="$store.appearance.value === @js($value)"
                    x-bind:class="$store.appearance.value === @js($value)
                        ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900'
                        : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800'"
                    class="flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium"
                >
                    <x-ui.icon :name="$mode['icon']" class="size-4" />
                    {{ $mode['label'] }}
                </button>
            @endforeach
        </div>
    </x-pages::settings.layout>
</section>
