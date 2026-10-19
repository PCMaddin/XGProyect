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
        <meta http-equiv="Content-Type" content="text/html;charset=UTF-8">
        <meta name="generator" content="XG Proyect {{ config('version.files') }}" />
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <script type="text/javascript" src="{{ asset('assets/js/overlib-min.js') }}"></script>
        <script type="text/javascript" src="{{ asset('assets/js/post-links.js') }}"></script>
        @yield('metatags')
    </head>
    <body>
        <div id="container">
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
                    @yield('content')
                    <p class="legal-links" style="text-align:center;font-size:11px;margin:24px 0 8px;">@include('legal.links')</p>
                </div>
            </div>
        </div>
    </body>
</html>