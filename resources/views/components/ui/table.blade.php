<div {{ $attributes->class('relative overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700') }}>
    <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
        @isset($head)
            <thead class="bg-zinc-50 text-xs tracking-wide text-zinc-500 uppercase dark:bg-zinc-800/50 dark:text-zinc-400">
                <tr>{{ $head }}</tr>
            </thead>
        @endisset
        <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
            {{ $slot }}
        </tbody>
    </table>
</div>
