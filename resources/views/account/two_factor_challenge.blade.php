@extends('account.plain')

@section('title', __('account/two_factor.tf_challenge_title'))

@section('content')
    <h1>{{ __('account/two_factor.tf_challenge_title') }}</h1>
    @if (session('error'))
        <div class="note error" role="alert">{{ session('error') }}</div>
    @endif
    <section>
        <p class="lead">{{ __('account/two_factor.tf_challenge_lead') }}</p>
        <form method="POST" action="{{ route('account.two-factor.verify') }}">
            @csrf
            <label for="code">{{ __('account/two_factor.tf_code') }}</label>
            <input type="text" id="code" name="code" class="code" inputmode="text" autocomplete="one-time-code" autofocus required maxlength="20">
            <div><button type="submit">{{ __('account/two_factor.tf_verify') }}</button></div>
        </form>
    </section>
    <div class="links">
        <a href="{{ $admin ? route('admin.index') : route('home.index') }}">{{ __('account/two_factor.tf_cancel') }}</a>
    </div>
@endsection
