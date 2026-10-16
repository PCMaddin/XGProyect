@extends('master.game')

@section('content')
<x-notice width="519px" :color="$color" :message="$message" />

<table width="665px">
    <tr>
        <th width="50%">
            <a href="game.php?page=traderResources" title="{{ __('game/trader.tr_resource_market_title') }}">
                {{ __('game/trader.tr_resource_market') }}
            </a>
        </th>
        <th width="50%">
            <a href="game.php?page=traderScrap" title="{{ __('game/trader.tr_scrap_merchant_title') }}">
                {{ __('game/trader.tr_scrap_merchant') }}
            </a>
        </th>
    </tr>
    <tr>
        <th width="50%">
            <a href="game.php?page=traderImportExport" title="{{ __('game/trader.tr_import_export_title') }}">
                {{ __('game/trader.tr_import_export') }}
            </a>
        </th>
        <th width="50%"></th>
    </tr>
</table>

{!! $currentMode !!}
@endsection
