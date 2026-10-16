@php($tierColors = ['bronze' => '#cd7f32', 'silver' => '#c0c0c0', 'gold' => '#ffd700'])
<table width="665px">
    <tr>
        <td class="c" colspan="3">{{ __('game/trader.tr_import_export') }}</td>
    </tr>
    <tr>
        <th colspan="3">
            <p>{{ __('game/trader.tr_ie_explanation') }}</p>
            <h2 style="color: {{ $tierColors[$tier] }}">{{ sprintf(__('game/trader.tr_ie_container'), $tierName) }}</h2>
        </th>
    </tr>
    @if ($bought)
    <tr>
        <th colspan="3">
            {{ sprintf(__('game/trader.tr_ie_content'), $boughtItem) }}<br>
            {{ __('game/trader.tr_ie_come_back') }}
        </th>
    </tr>
    @else
    <tr>
        @foreach ($costs as $resource => $cost)
        <th>
            <img border="0" src="{{ asset('assets/upload/skins/xgproyect/resources/' . $resource . '.gif') }}" width="42" height="22" alt=""/><br>
            {{ $cost }} {{ __('game/global.' . $resource) }}
            <form method="POST" action="game.php?page=traderImportExport">
                @csrf
                <input type="hidden" name="resource" value="{{ $resource }}">
                <input type="submit" name="buy" value="{{ __('game/trader.tr_ie_buy') }}">
            </form>
        </th>
        @endforeach
    </tr>
    <tr>
        <th colspan="3">
            @if ($swapsLeft > 0)
            <form method="POST" action="game.php?page=traderImportExport">
                @csrf
                {{ sprintf(__('game/trader.tr_ie_swap_info'), $swapPrice, $swapsLeft) }}<br>
                <input type="submit" name="swap" value="{{ __('game/trader.tr_ie_swap') }}">
            </form>
            @else
            {{ __('game/trader.tr_ie_no_swaps') }}
            @endif
        </th>
    </tr>
    @endif
</table>
