<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title')</title>
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
    <style>
        body { margin: 0; background: #0b0f1a; color: #d8dde8; font: 15px/1.5 Verdana, Arial, sans-serif; }
        main { max-width: 640px; margin: 0 auto; padding: 32px 16px; display: grid; gap: 18px; }
        h1 { font-size: 24px; margin: 0; color: #fff; }
        h2 { font-size: 17px; margin: 0 0 8px; color: #fff; }
        p { margin: 0 0 10px; }
        .lead { color: #9aa4b8; }
        section { background: #151b2b; border: 1px solid #263049; border-radius: 8px; padding: 16px; }
        .status { display: inline-block; padding: 1px 10px; border-radius: 10px; font-size: 13px; }
        .status.on { background: #12301f; color: #6fe3a0; }
        .status.off { background: #3d2a12; color: #ffbf66; }
        .note { border-radius: 6px; padding: 10px 12px; font-size: 14px; }
        .note.error { background: #3d1c1a; color: #ff9b91; }
        .note.success { background: #12301f; color: #6fe3a0; }
        .note.warning { background: #3d2a12; color: #ffbf66; }
        label { display: block; font-size: 13px; color: #9aa4b8; margin: 8px 0 4px; }
        textarea { width: 100%; box-sizing: border-box; background: #0b0f1a; color: #fff; border: 1px solid #263049; border-radius: 6px; padding: 8px 10px; font: 15px/1.5 Verdana, Arial, sans-serif; }
        input[type=text], input[type=password] { width: 100%; max-width: 280px; box-sizing: border-box; background: #0b0f1a; color: #fff; border: 1px solid #263049; border-radius: 6px; padding: 8px 10px; font: 16px Verdana, Arial, sans-serif; }
        input.code { letter-spacing: .2em; font-family: "Courier New", monospace; }
        button { background: #4f7cff; color: #fff; border: 0; border-radius: 6px; padding: 8px 18px; font-size: 15px; cursor: pointer; margin-top: 10px; }
        button:hover { background: #3d68e6; }
        button.secondary { background: #263049; }
        button.danger { background: #8c2a22; }
        .qr { background: #fff; display: inline-block; padding: 8px; border-radius: 6px; line-height: 0; }
        .qr svg { width: 200px; height: 200px; }
        code, .codes { font-family: "Courier New", monospace; color: #fff; }
        .codes { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px 18px; font-size: 16px; margin: 10px 0; padding: 0; list-style: none; }
        label.check { display: flex; gap: 8px; align-items: flex-start; color: #d8dde8; font-size: 14px; margin-top: 12px; }
        .links { display: flex; flex-wrap: wrap; gap: 8px 18px; font-size: 14px; }
        footer.legal { text-align: center; font-size: 13px; color: #9aa4b8; }
        footer.legal a { color: #9aa4b8; }
        a { color: #8fa9ff; }
        :focus-visible { outline: 2px solid #8fa9ff; outline-offset: 2px; }
    </style>
</head>
<body>
<main>
    @yield('content')
    <footer class="legal">@include('legal.links')</footer>
</main>
</body>
</html>
