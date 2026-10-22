<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $text_locale) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('home/legal.lg_' . $page) }}</title>
    <style>
        :root { --bg: #f6f7f9; --panel: #fff; --text: #1d2330; --muted: #5f6b7a; --line: #d9dde3; --accent: #2457c5; }
        @media (prefers-color-scheme: dark) { :root { --bg: #12151b; --panel: #1b2029; --text: #e6e9ee; --muted: #9aa4b2; --line: #2d3440; --accent: #6d9eff; } }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; }
        nav { display: flex; flex-wrap: wrap; gap: 6px 18px; padding: 12px 16px; background: var(--panel); border-bottom: 1px solid var(--line); font-size: 14px; }
        nav a { color: var(--accent); }
        nav a[aria-current] { color: var(--text); font-weight: 600; text-decoration: none; }
        main { max-width: 820px; margin: 0 auto; padding: 24px 16px 48px; overflow-wrap: anywhere; }
        main a { color: var(--accent); }
        h1 { font-size: 1.6rem; } h2 { font-size: 1.25rem; margin-top: 2em; } h3 { font-size: 1.05rem; }
        table { border-collapse: collapse; } th, td { border: 1px solid var(--line); padding: 4px 8px; }
        footer { color: var(--muted); font-size: 13px; border-top: 1px solid var(--line); margin-top: 40px; padding-top: 12px; }
    </style>
</head>
<body>
<nav aria-label="{{ __('home/legal.lg_title') }}">
    <a href="{{ url('/') }}">← {{ __('home/legal.lg_back') }}</a>
    @foreach ($pages as $link)
        <a href="{{ route('legal.show', ['page' => $link, 'lang' => app()->getLocale()]) }}"@if ($link === $page) aria-current="page"@endif>{{ __('home/legal.lg_' . $link) }}</a>
    @endforeach
</nav>
<main>
    {!! $html !!}
    <footer>{{ __('home/legal.lg_updated', ['date' => date('Y-m-d', $updated_at)]) }}</footer>
</main>
</body>
</html>
