{{-- The public pay page's frame: the ISP's logo or name, one card, and their support number. No panel links. --}}
@props(['title' => null, 'company' => '', 'logo' => null, 'phone' => '', 'phoneLink' => ''])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => $title])
        <meta name="robots" content="noindex">
    </head>
    <body class="min-h-screen bg-paper text-zinc-900 antialiased dark:bg-ink dark:text-zinc-100">
        <main class="mx-auto flex min-h-svh w-full max-w-md flex-col gap-6 px-4 py-8 sm:py-12">
            <header class="flex flex-col items-center gap-2 text-center">
                @if ($logo)
                    <img src="{{ $logo }}" alt="{{ $company }}" class="max-h-16 max-w-56 object-contain">
                @endif
                @if ($company !== '')
                    <p class="text-lg font-semibold">{{ $company }}</p>
                @endif
            </header>

            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm sm:p-6 dark:border-zinc-800 dark:bg-zinc-900">
                {{ $slot }}
            </div>

            @if ($phone !== '')
                <p class="text-center text-sm text-zinc-600 dark:text-zinc-400">
                    {{ __('Questions? Call us:') }} <a href="tel:{{ $phoneLink }}" class="font-medium underline-offset-2 hover:underline">{{ $phone }}</a>
                </p>
            @endif
        </main>
    </body>
</html>
