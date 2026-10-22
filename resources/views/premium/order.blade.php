@extends('master.game')

@section('metatags')
@if ($refresh)
<meta http-equiv="refresh" content="{{ $wax === null ? 5 : 30 }}">
@endif
@endsection

@section('content')
<table role="presentation" width="600">
    <tr>
        <td role="heading" aria-level="2" colspan="2" class="c">{{ __('game/darkmatter.dm_order', ['id' => $order_id]) }}</td>
    </tr>
    @if ($cancelled)
    <tr>
        <th colspan="2" style="color:orange;">{{ __('game/darkmatter.dm_cancelled') }}</th>
    </tr>
    @endif
    <tr>
        <th style="text-align:left;">{{ __('game/global.darkmatter') }}</th>
        <th><strong style="color:skyblue;">{{ $dark_matter }}</strong></th>
    </tr>
    <tr>
        <th style="text-align:left;">{{ __('game/darkmatter.dm_price') }}</th>
        <th>{{ $price }}</th>
    </tr>
    @if ($wax !== null)
    <tr>
        <th colspan="2" style="text-align:left;">{{ __('game/darkmatter.dm_wax_howto', ['until' => $wax['until']]) }}</th>
    </tr>
    <tr>
        <th style="text-align:left;">{{ __('game/darkmatter.dm_wax_account') }}</th>
        <th><code id="wax-account" style="user-select:all;">{{ $wax['account'] }}</code></th>
    </tr>
    <tr>
        <th style="text-align:left;">{{ __('game/darkmatter.dm_wax_amount') }}</th>
        <th><code id="wax-amount" style="user-select:all;" title="{{ $wax['quantity'] }}">{{ $wax['amount'] }}</code></th>
    </tr>
    <tr>
        <th style="text-align:left;">{{ __('game/darkmatter.dm_wax_memo') }}</th>
        <th><code id="wax-memo" style="user-select:all;color:orange;">{{ $wax['memo'] }}</code></th>
    </tr>
    <tr>
        <th colspan="2" style="text-align:left;color:orange;">{{ __('game/darkmatter.dm_wax_memo_warning') }}</th>
    </tr>
    @endif
    <tr>
        <th style="text-align:left;">{{ __('game/darkmatter.dm_status') }}</th>
        <th id="order-status" data-status="{{ $status }}">
            <strong style="color:{{ $status === 'paid' ? 'lime' : ($status === 'pending' ? 'orange' : 'red') }};">{{ $status_text }}</strong>
        </th>
    </tr>
    <tr>
        <th colspan="2">
            @if ($status === 'pending')
            <a href="game.php?page=darkmatter&amp;order={{ $order_id }}">{{ __('game/darkmatter.dm_reload') }}</a> &middot;
            @endif
            <a href="game.php?page=darkmatter">{{ __('game/darkmatter.dm_title') }}</a> &middot;
            <a href="game.php?page=premium">{{ __('game/darkmatter.dm_back') }}</a>
        </th>
    </tr>
</table>
@endsection
