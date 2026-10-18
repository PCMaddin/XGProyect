@extends('account.plain')

@section('title', __('account/two_factor.tf_title'))

@section('content')
    <h1>{{ __('account/two_factor.tf_title') }}
        <span class="status {{ $enabled ? 'on' : 'off' }}">{{ $enabled ? __('account/two_factor.tf_status_on') : __('account/two_factor.tf_status_off') }}</span>
    </h1>

    @if ($required && !$enabled)
        <div class="note warning" role="alert">{{ __('account/two_factor.tf_required') }}</div>
    @endif
    @if (session('error'))
        <div class="note error" role="alert">{{ session('error') }}</div>
    @endif
    @if (session('success'))
        <div class="note success" role="status">{{ session('success') }}</div>
    @endif

    <p class="lead">{{ __('account/two_factor.tf_lead') }}</p>

    @if ($recovery_codes !== [])
        <section id="recovery-codes">
            <h2>{{ __('account/two_factor.tf_codes_title') }}</h2>
            <p>{{ __('account/two_factor.tf_codes_lead') }}</p>
            <ul class="codes">
                @foreach ($recovery_codes as $code)
                    <li>{{ $code }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    @if (!$enabled && $setup_secret === null)
        <section>
            <form method="POST" action="{{ route('account.security.start') }}">
                @csrf
                <button type="submit">{{ __('account/two_factor.tf_start') }}</button>
            </form>
        </section>
    @elseif (!$enabled)
        <section id="setup">
            <p>{{ __('account/two_factor.tf_step_scan') }}</p>
            <div class="qr">{!! $setup_qr !!}</div>
            <p><code id="setup-secret">{{ $setup_secret }}</code></p>
            <form method="POST" action="{{ route('account.security.confirm') }}">
                @csrf
                <label for="confirm-code">{{ __('account/two_factor.tf_step_confirm') }}</label>
                <input type="text" id="confirm-code" name="code" class="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="10">
                <div><button type="submit">{{ __('account/two_factor.tf_confirm') }}</button></div>
            </form>
        </section>
    @else
        <section>
            <h2>{{ __('account/two_factor.tf_renew_title') }}</h2>
            <p>{{ __('account/two_factor.tf_codes_left', ['count' => $codes_left]) }} {{ __('account/two_factor.tf_renew_lead') }}</p>
            <form method="POST" action="{{ route('account.security.codes') }}">
                @csrf
                <label for="codes-password">{{ __('account/two_factor.tf_password') }}</label>
                <input type="password" id="codes-password" name="password" autocomplete="current-password" required>
                <div><button type="submit" class="secondary">{{ __('account/two_factor.tf_renew') }}</button></div>
            </form>
        </section>
        @if (!($admin && config('auth.two_factor.admin_required')))
            <section>
                <h2>{{ __('account/two_factor.tf_disable_title') }}</h2>
                <p>{{ __('account/two_factor.tf_disable_lead') }}</p>
                <form method="POST" action="{{ route('account.security.disable') }}">
                    @csrf
                    <label for="disable-password">{{ __('account/two_factor.tf_password') }}</label>
                    <input type="password" id="disable-password" name="password" autocomplete="current-password" required>
                    <label for="disable-code">{{ __('account/two_factor.tf_code') }}</label>
                    <input type="text" id="disable-code" name="code" class="code" autocomplete="one-time-code" required maxlength="20">
                    <div><button type="submit" class="danger">{{ __('account/two_factor.tf_disable') }}</button></div>
                </form>
            </section>
        @endif
    @endif

    <div class="links">
        <a href="{{ url('game.php?page=overview') }}">{{ __('account/two_factor.tf_to_game') }}</a>
        @if ($admin)
            <a href="{{ route('admin.index') }}">{{ __('account/two_factor.tf_to_admin') }}</a>
        @endif
    </div>
@endsection
