@extends('operator.layout')

@section('title', __('operator/operator.op_account'))

@section('content')
<h1>{{ __('operator/operator.op_account') }}</h1>
<div class="panel">
    <p>{{ $operator->email }}</p>
    <p class="help">
        {{ __('operator/operator.op_last_login') }}: {{ $operator->last_login_at === null ? '-' : date('Y-m-d H:i', $operator->last_login_at) }} ({{ $operator->last_login_ip ?? '-' }})<br>
        {{ __('operator/operator.op_codes_left', ['count' => $codes_left]) }}<br>
        {{ __('operator/operator.op_new_app_help') }}
    </p>
</div>

@if (is_array($new_codes))
    <div class="panel" id="new-codes">
        <h2>{{ __('operator/operator.op_new_codes') }}</h2>
        <p>{{ __('operator/operator.op_codes_shown_once') }}</p>
        <pre class="codes">{{ implode("\n", $new_codes) }}</pre>
    </div>
@endif

<h2>{{ __('operator/operator.op_change_password') }}</h2>
<form method="post" action="{{ route('operator.account.password') }}" class="panel">
    @csrf
    <label for="password">{{ __('operator/operator.op_new_password') }}</label>
    <input type="password" id="password" name="password" autocomplete="new-password" minlength="12" required>
    <label for="password_confirmation">{{ __('operator/operator.op_new_password_again') }}</label>
    <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" minlength="12" required>
    <label for="current_password">{{ __('operator/operator.op_current_password') }}</label>
    <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
    <label for="code">{{ __('operator/operator.op_code') }}</label>
    <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required>
    <div class="actions"><button type="submit">{{ __('operator/operator.op_change_password') }}</button></div>
</form>

<h2>{{ __('operator/operator.op_new_codes') }}</h2>
<form method="post" action="{{ route('operator.account.codes') }}" class="panel">
    @csrf
    <p class="help">{{ __('operator/operator.op_new_codes_help') }}</p>
    <label for="codes_current_password">{{ __('operator/operator.op_current_password') }}</label>
    <input type="password" id="codes_current_password" name="current_password" autocomplete="current-password" required>
    <label for="codes_code">{{ __('operator/operator.op_code') }}</label>
    <input type="text" id="codes_code" name="code" inputmode="numeric" autocomplete="one-time-code" required>
    <div class="actions"><button type="submit">{{ __('operator/operator.op_new_codes') }}</button></div>
</form>
@endsection
