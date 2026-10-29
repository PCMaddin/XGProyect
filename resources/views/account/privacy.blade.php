@extends('account.plain')

@section('title', __('account/privacy.pv_title'))

@section('content')
    <h1>{{ __('account/privacy.pv_title') }}</h1>

    @if (session('error'))
        <div class="note error" role="alert">{{ session('error') }}</div>
    @endif

    <p class="lead">{{ __('account/privacy.pv_lead') }}</p>

    <section id="export">
        <h2>{{ __('account/privacy.pv_export_title') }}</h2>
        <p>{{ __('account/privacy.pv_export_lead') }}</p>
        <form method="POST" action="{{ route('account.privacy.export') }}">
            @csrf
            <label for="export-password">{{ __('account/privacy.pv_password') }}</label>
            <input type="password" id="export-password" name="password" autocomplete="current-password" required>
            @if ($two_factor)
                <label for="export-code">{{ __('account/privacy.pv_code') }}</label>
                <input type="text" id="export-code" name="code" class="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="10">
            @endif
            <div><button type="submit">{{ __('account/privacy.pv_export_button') }}</button></div>
        </form>
    </section>

    <section id="delete">
        <h2>{{ __('account/privacy.pv_delete_title') }}</h2>
        @if ($admin)
            <p>{{ __('account/privacy.pv_admin') }}</p>
        @else
            <p>{{ __('account/privacy.pv_delete_lead') }}</p>
            <p class="lead">{{ __('account/privacy.pv_delete_kept') }}</p>
            <form method="POST" action="{{ route('account.privacy.delete') }}">
                @csrf
                <label for="delete-password">{{ __('account/privacy.pv_password') }}</label>
                <input type="password" id="delete-password" name="password" autocomplete="current-password" required>
                @if ($two_factor)
                    <label for="delete-code">{{ __('account/privacy.pv_code') }}</label>
                    <input type="text" id="delete-code" name="code" class="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="10">
                @endif
                <label class="check"><input type="checkbox" name="confirm" value="1" required> {{ __('account/privacy.pv_delete_confirm') }}</label>
                <div><button type="submit" class="danger">{{ __('account/privacy.pv_delete_button') }}</button></div>
            </form>
        @endif
    </section>

    <div class="links">
        <a href="{{ url('game.php?page=preferences') }}">{{ __('account/privacy.pv_to_game') }}</a>
        @foreach (app(\App\Services\Legal\LegalPages::class)->written() as $legalPage)
            @if ($legalPage === 'privacy')
                <a href="{{ route('legal.show', ['page' => 'privacy', 'lang' => app()->getLocale()]) }}">{{ __('home/legal.lg_privacy') }}</a>
            @endif
        @endforeach
    </div>
@endsection
