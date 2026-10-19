<!DOCTYPE html>
<html lang="{{ __('game/global.lang_code') }}">
    <head>
        <title>{{ $gameTitle }}</title>
        <link rel="shortcut icon" href="favicon.ico">
        <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/base.css') }}">
        <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/default.css') }}">
        <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/redesign.css') }}">
        <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/formate.css') }}">
        <link rel="stylesheet" type="text/css" href="{{ asset('assets/upload/skins/xgproyect/formate.css') }}">
        <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/mobile.css') }}">
        <meta http-equiv="Content-Type" content="text/html;charset=UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="generator" content="XG Proyect {{ config('version.files') }}" />
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <script type="text/javascript" src="{{ asset('assets/js/overlib-min.js') }}"></script>
        <script type="text/javascript" src="{{ asset('assets/js/post-links.js') }}"></script>
        @yield('metatags')
    </head>
    <body>
        <div id="container">
            @if (!isset($noLeftMenu))
            {{-- on a phone the menu folds away behind this button (assets/css/mobile.css) --}}
            <button type="button" id="menu-toggle" aria-controls="menu" aria-expanded="false"
                onclick="var open = document.body.classList.toggle('menu-open'); this.setAttribute('aria-expanded', open);">&#9776; {{ __('game/menu.lm_menu') }}</button>
            @endif
            <div id="menu">
                @if (!isset($noLeftMenu))
                <x-game.leftmenu />
                @endif
            </div>
            <div id="page-content">
                <div id="navbar">
                    @if (!isset($noTopnav))
                    <x-game.topnav />
                    @endif
                </div>
                <div id="content" role="main">
                    <br>
                    <x-game.verify-email />
                    @yield('content')
                    <p class="legal-links" style="text-align:center;font-size:11px;margin:24px 0 8px;">
                        <a href="{{ route('account.report', ['from' => request()->getRequestUri()]) }}" id="report-problem">{{ __('account/report.rp_link') }}</a>
                        @if (app(\App\Services\Legal\LegalPages::class)->written() !== []) · @endif
                        @include('legal.links')
                    </p>
                </div>
            </div>
        </div>
    </body>
</html>