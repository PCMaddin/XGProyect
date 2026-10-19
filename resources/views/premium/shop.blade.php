@extends('master.game')

@section('content')
<form action="game.php?page=darkmatter" method="post" id="dark-matter-shop">
@csrf
<table role="presentation" width="600">
    <tr>
        <td role="heading" aria-level="2" colspan="3" class="c">{{ __('game/darkmatter.dm_title') }}</td>
    </tr>
    <tr>
        <td class="l" style="width:120px;">
            <img src="{{ asset('assets/upload/skins/xgproyect/premium/DMaterie.jpg') }}" width="120" height="120" alt=""/>
        </td>
        <td class="l" colspan="2">
            @foreach ($methods as $method)
                <p>{{ __('game/darkmatter.dm_intro_' . $method, ['symbol' => $token_symbol]) }}</p>
            @endforeach
        </td>
    </tr>
    @if ($test_payments)
    <tr>
        <th colspan="3" id="test-payments" style="text-align:left;color:#7fd8ff;">
            {{ __('game/darkmatter.dm_test_payments') }}
        </th>
    </tr>
    @endif
    @if ($two_factor_hint)
    <tr>
        <th colspan="3" id="two-factor-hint" style="text-align:left;color:orange;">
            {{ __('game/darkmatter.dm_two_factor_hint') }}
            <a href="{{ route('account.security') }}">{{ __('game/darkmatter.dm_two_factor_link') }}</a>
        </th>
    </tr>
    @endif
    <tr>
        <td class="c"></td>
        <td class="c">{{ __('game/global.darkmatter') }}</td>
        <td class="c">{{ __('game/darkmatter.dm_price') }}</td>
    </tr>
    @foreach ($packages as $package)
    <tr>
        <th style="text-align:center;">
            <input type="radio" name="package" value="{{ $package['id'] }}" id="package-{{ $package['id'] }}" required @checked($loop->first)/>
        </th>
        <th>
            <label for="package-{{ $package['id'] }}">
                <img src="{{ asset('assets/upload/skins/xgproyect/premium/dm_klein_1.jpg') }}" width="32" height="32" style="vertical-align:middle;" alt=""/>
                <strong style="color:skyblue;">{{ $package['dark_matter'] }}</strong>
            </label>
        </th>
        <th>
            <strong style="color:lime;">{{ $package['price'] }}</strong>
            @if ($package['wax'] !== '')
                <br/><small>{{ __('game/darkmatter.dm_wax_about', ['amount' => $package['wax']]) }}</small>
            @endif
            @if ($package['token'] !== '')
                <br/><small>{{ $package['token'] }}@if ($package['token_market'] !== '') ({{ __('game/darkmatter.dm_token_market', ['amount' => $package['token_market']]) }})@endif</small>
            @endif
        </th>
    </tr>
    @endforeach
    <tr>
        <td class="c" colspan="3"><small>{{ __('game/darkmatter.dm_price_note') }}</small></td>
    </tr>
    <tr>
        <th colspan="3" style="text-align:left;">
            <label for="withdrawal-waiver" style="display:flex;gap:6px;align-items:flex-start;">
                <input type="checkbox" name="withdrawal_waiver" id="withdrawal-waiver" value="1" required/>
                <span>
                    {{ __('game/darkmatter.dm_withdrawal_waiver') }}
                    @if ($withdrawal_link)
                        <a href="{{ route('legal.show', ['page' => 'withdrawal', 'lang' => app()->getLocale()]) }}" target="_blank" rel="noopener">{{ __('home/legal.lg_withdrawal') }}</a>
                    @endif
                    @if ($terms_link)
                        · <a href="{{ route('legal.show', ['page' => 'terms', 'lang' => app()->getLocale()]) }}" target="_blank" rel="noopener">{{ __('home/legal.lg_terms') }}</a>
                    @endif
                </span>
            </label>
        </th>
    </tr>
    <tr>
        <th colspan="3">
            @foreach ($methods as $method)
                <button type="submit" name="method" value="{{ $method }}" style="margin:2px 4px;">{{ __('game/darkmatter.dm_buy_' . $method, ['symbol' => $token_symbol]) }}</button>
            @endforeach
        </th>
    </tr>
    <tr>
        <th colspan="3">
            <a href="game.php?page=premium">{{ __('game/darkmatter.dm_back') }}</a>
        </th>
    </tr>
</table>
</form>
@endsection
