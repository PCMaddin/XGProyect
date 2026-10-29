@extends('operator.layout')

@section('title', __('operator/operator.op_code_title'))

@section('content')
<div class="panel narrow">
    <h1>{{ __('operator/operator.op_code_title') }}</h1>
    <p>{{ __('operator/operator.op_code_help') }}</p>
    <form method="post" action="{{ route('operator.code.verify') }}">
        @csrf
        <label for="code">{{ __('operator/operator.op_code') }}</label>
        <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus>
        <div class="actions">
            <button type="submit">{{ __('operator/operator.op_confirm') }}</button>
            <a href="{{ route('operator.login') }}">{{ __('operator/operator.op_back_to_login') }}</a>
        </div>
    </form>
</div>
@endsection
