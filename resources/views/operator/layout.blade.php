<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · {{ __('operator/operator.op_title') }}</title>
    <style>
        :root { --bg: #f4f5f7; --panel: #fff; --text: #1d2330; --muted: #5f6b7a; --line: #d9dde3; --accent: #2457c5; --danger: #b42318; --ok: #1a7f37; --warn: #9a6700; }
        @media (prefers-color-scheme: dark) { :root { --bg: #12151b; --panel: #1b2029; --text: #e6e9ee; --muted: #9aa4b2; --line: #2d3440; --accent: #6d9eff; --danger: #ff7b72; --ok: #56d364; --warn: #e3b341; } }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
        header { display: flex; flex-wrap: wrap; gap: 8px 20px; align-items: center; padding: 12px 20px; background: var(--panel); border-bottom: 1px solid var(--line); }
        header strong { margin-right: auto; }
        header form { margin: 0; }
        main { max-width: 1100px; margin: 0 auto; padding: 20px 16px 48px; }
        a { color: var(--accent); }
        h1 { font-size: 1.4rem; margin: 0 0 16px; }
        h2 { font-size: 1.1rem; margin: 28px 0 10px; }
        .panel { background: var(--panel); border: 1px solid var(--line); border-radius: 8px; padding: 16px; margin-bottom: 16px; overflow-x: auto; }
        .narrow { max-width: 420px; margin: 40px auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
        th { color: var(--muted); font-weight: 600; }
        label { display: block; font-weight: 600; margin: 12px 0 4px; }
        .help { color: var(--muted); font-size: 13px; font-weight: normal; }
        input[type=text], input[type=email], input[type=password], input[type=number], input[type=datetime-local], select, textarea { width: 100%; padding: 8px 10px; border: 1px solid var(--line); border-radius: 6px; background: var(--bg); color: var(--text); font: inherit; }
        .checks label { display: inline-flex; gap: 6px; align-items: center; font-weight: normal; margin-right: 16px; }
        button, .button { display: inline-block; padding: 8px 14px; border: 0; border-radius: 6px; background: var(--accent); color: #fff; font: inherit; cursor: pointer; text-decoration: none; }
        button.link { background: none; color: var(--accent); padding: 0; }
        button.danger { background: var(--danger); }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 16px; }
        .flash { padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; border: 1px solid var(--line); background: var(--panel); }
        .flash.ok { border-color: var(--ok); }
        .flash.error { border-color: var(--danger); color: var(--danger); }
        .badge { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 12px; border: 1px solid currentColor; }
        .badge.ok { color: var(--ok); } .badge.warn { color: var(--warn); } .badge.muted { color: var(--muted); }
        pre, code, .codes { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; }
        pre { background: var(--bg); border: 1px solid var(--line); border-radius: 6px; padding: 12px; overflow-x: auto; white-space: pre; }
        textarea { min-height: 420px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; line-height: 1.5; }
        .preview { line-height: 1.6; }
        .langs { font-size: 13px; color: var(--muted); }
        .langs a { margin-left: 4px; }
    </style>
</head>
<body>
<header>
    <strong>{{ __('operator/operator.op_title') }}</strong>
    @auth('operator')
        <a href="{{ route('operator.universes') }}">{{ __('operator/operator.op_universes') }}</a>
        <a href="{{ route('operator.legal') }}">{{ __('operator/operator.op_legal') }}</a>
        <a href="{{ route('operator.account') }}">{{ __('operator/operator.op_account') }}</a>
        <span class="help">{{ auth('operator')->user()?->email }}</span>
        <form method="post" action="{{ route('operator.logout') }}">@csrf<button type="submit" class="link">{{ __('operator/operator.op_logout') }}</button></form>
    @endauth
    <span class="langs">@foreach (\App\Http\Middleware\OperatorLocale::languages() as $lang)<a href="?lang={{ $lang }}" @if ($lang === app()->getLocale()) aria-current="true" @endif>{{ $lang }}</a>@endforeach</span>
</header>
<main>
    @if (session('status'))
        <div class="flash ok" role="status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="flash error" role="alert">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
    @endif
    @yield('content')
</main>
</body>
</html>
