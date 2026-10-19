<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $gameName }}</title>
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/home/reset.css') }}" media="screen" />
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/home/recover.css') }}" media="screen" />
</head>
<body id="login">
    <form id="recoverpassword" name="resetpassword" method="POST" action="{{ route('account.reset.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}" />
        <input type="hidden" name="email" value="{{ $email }}" />
        <h1><span>{{ $gameName }}</span></h1>
        @if(Session::has('message') || $errors->any())
        <div id="error">
            <p>{{ Session::get('message', __('account/recover.re_reset_invalid')) }}</p>
        </div>
        @endif
        <div id="loginwrapper">
            <h2>{{ __('account/recover.re_reset_title', ['game' => $gameName]) }}</h2>
            <div class="textLeft wrap-inner">
                <label for="password">{{ __('account/recover.re_new_password') }}</label>
                <input type="password" name="password" id="password" tabindex="1" class="input" autocomplete="new-password" />
                <label for="password_confirmation">{{ __('account/recover.re_confirm_password') }}</label>
                <input type="password" name="password_confirmation" id="password_confirmation" tabindex="2" class="input" autocomplete="new-password" />
                <input type="submit" value="{{ __('account/recover.re_reset_value') }}" tabindex="3" class="start" />
            </div>
            <br class="clear" />
        </div>
    </form>
    <p id="legal" style="text-align:center;font-size:11px;margin:16px 0;">@include('legal.links')</p>
</body>
</html>
