@extends('operator.layout')

@section('title', __('operator/operator.op_login_title'))

@section('content')
<div class="panel narrow">
    <h1>{{ __('operator/operator.op_login_title') }}</h1>
    <form method="post" action="{{ route('operator.login.submit') }}">
        @csrf
        <label for="email">{{ __('operator/operator.op_email') }}</label>
        <input type="email" id="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
        <label for="password">{{ __('operator/operator.op_password') }}</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>
        <div class="actions"><button type="submit">{{ __('operator/operator.op_sign_in') }}</button></div>
    </form>
    <p class="help">{{ __('operator/operator.op_login_hint') }}</p>
</div>
@endsection
