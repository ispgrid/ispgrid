{{-- A long form's buttons, kept in view at the bottom of the window. The negative margins match the page padding in layouts/app. --}}
<div {{ $attributes->class('sticky bottom-0 z-10 -mx-6 flex flex-wrap gap-2 border-t border-zinc-200 bg-white/95 px-6 py-4 backdrop-blur lg:-mx-8 lg:px-8 dark:border-zinc-700 dark:bg-zinc-900/95') }}>
    {{ $slot }}
</div>
