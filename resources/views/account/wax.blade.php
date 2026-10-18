@extends('account.plain')

@section('title', __('account/wax.wx_title'))

@section('content')
    <h1>{{ __('account/wax.wx_title') }}
        <span class="status {{ $linked !== null ? 'on' : 'off' }}">{{ $linked !== null ? __('account/wax.wx_linked', ['account' => $linked]) : __('account/wax.wx_not_linked') }}</span>
    </h1>

    @if (session('error'))
        <div class="note error" role="alert">{{ session('error') }}</div>
    @endif
    @if (session('success'))
        <div class="note success" role="status">{{ session('success') }}</div>
    @endif

    <p class="lead">{{ __('account/wax.wx_lead') }}</p>

    @if ($shop_account === '')
        <div class="note warning">{{ __('account/wax.wx_unavailable') }}</div>
    @else
        <section id="link">
            <p>{{ $linked === null ? __('account/wax.wx_howto') : __('account/wax.wx_relink') }}</p>
            <p>{{ __('account/wax.wx_account') }}: <code id="wax-shop">{{ $shop_account }}</code></p>
            <p>{{ __('account/wax.wx_memo') }}: <code id="wax-link-memo" style="color:#ffbf66;">{{ $memo }}</code></p>
            <form method="POST" action="{{ route('account.wax.renew') }}">
                @csrf
                <button type="submit" class="secondary">{{ __('account/wax.wx_renew') }}</button>
            </form>
        </section>
    @endif

    @if ($linked !== null)
        <section id="rewards">
            <h2>{{ __('account/wax.wx_rewards_title') }}</h2>
            @if ($rewards === [])
                <p>{{ __('account/wax.wx_rewards_none') }}</p>
            @else
                @if (!$can_claim)
                    <div class="note warning">{{ __('account/wax.wx_claim_pay2win_only') }}</div>
                @endif
                @foreach ($rewards as $reward)
                    <form method="POST" action="{{ route('account.wax.claim') }}" style="display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;margin:8px 0;">
                        @csrf
                        <input type="hidden" name="reward" value="{{ $reward['id'] }}">
                        <span>{{ __('account/wax.wx_reward_line', ['universe' => $reward['universe_name'], 'category' => __('home/lobby.lb_category_' . $reward['category']), 'rank' => $reward['rank']]) }}</span>
                        <strong style="color:#c7b8ff;">{{ number_format($reward['reward'], 0, ',', '.') }} {{ __('home/lobby.lb_dm') }}</strong>
                        @if ($can_claim)
                            <button type="submit" style="margin-top:0;">{{ __('account/wax.wx_claim') }}</button>
                        @endif
                    </form>
                @endforeach
            @endif
        </section>
    @endif

    <div class="links">
        <a href="{{ url('game.php?page=overview') }}">{{ __('account/two_factor.tf_to_game') }}</a>
        <a href="{{ route('account.security') }}">{{ __('account/two_factor.tf_title') }}</a>
    </div>
@endsection
