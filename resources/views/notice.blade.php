<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $ispName !== '' ? $ispName : __('Service notice') }}</title>
    <style>
        :root { --bg: #f4f6f8; --card: #ffffff; --text: #1d2733; --muted: #5b6876; --accent: #b4540a; color-scheme: light; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0f1419; --card: #18212b; --text: #e7edf3; --muted: #9aa8b6; --accent: #f0a35e; color-scheme: dark; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; background: var(--bg); color: var(--text); font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { width: 100%; max-width: 32rem; background: var(--card); border-radius: 16px; padding: 32px 28px; border-top: 4px solid var(--accent); }
        h1 { margin: 0 0 12px; font-size: 1.4rem; line-height: 1.3; text-wrap: balance; }
        p { margin: 0 0 16px; }
        .phone { display: inline-block; margin-top: 4px; font-size: 1.3rem; font-weight: 600; color: var(--accent); text-decoration: none; font-variant-numeric: tabular-nums; }
        .phone:focus-visible { outline: 2px solid var(--accent); outline-offset: 3px; }
        .muted { color: var(--muted); font-size: 0.9rem; margin: 20px 0 0; }
    </style>
</head>
<body>
    <main>
        <h1>{{ $ispName !== '' ? $ispName : __('Service notice') }}</h1>
        <p>{{ $message }}</p>
        @if ($phone !== '')
            <a class="phone" href="tel:{{ $phoneLink }}">{{ $phone }}</a>
        @endif
        <p class="muted">{{ __('Internet access comes back as soon as your account is renewed.') }}</p>
    </main>
</body>
</html>
