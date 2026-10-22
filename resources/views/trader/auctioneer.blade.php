@php($tierColors = ['bronze' => '#cd7f32', 'silver' => '#c0c0c0', 'gold' => '#ffd700'])
<table width="665px">
    <tr>
        <td class="c" colspan="2">{{ __('game/trader.tr_auctioneer') }}</td>
    </tr>
    <tr>
        <th colspan="2"><p>{{ __('game/trader.tr_au_explanation') }}</p></th>
    </tr>
    @if (!$auction)
    <tr>
        <th colspan="2">{{ __('game/trader.tr_au_no_auction') }} {{ sprintf(__('game/trader.tr_au_next'), $next) }}</th>
    </tr>
    @else
    <tr>
        <th style="width: 200px">{{ __('game/trader.tr_au_item') }}</th>
        <th style="text-align:left">
            <span style="color: {{ $tierColors[$tier] }}; font-size: 1.2em">{{ $itemName }}</span><br>
            {{ $itemDescription }}
        </th>
    </tr>
    <tr>
        <th colspan="2">
            @if ($running)
            {{ sprintf(__('game/trader.tr_au_time_left'), $timeLeft) }}
            @else
            {{ __('game/trader.tr_au_ended') }}
            @if ($winner !== '') {{ sprintf(__('game/trader.tr_au_winner'), $winner) }} @endif
            {{ sprintf(__('game/trader.tr_au_next'), $next) }}
            @endif
        </th>
    </tr>
    <tr>
        <th colspan="2">
            {{ $highest !== '' ? $highest : __('game/trader.tr_au_no_bids') }}<br>
            {{ sprintf(__('game/trader.tr_au_your_bid'), $ownBid) }}
        </th>
    </tr>
    @if ($running)
    <tr>
        <th colspan="2">
            <form method="POST" action="game.php?page=traderAuctioneer">
                @csrf
                {{ __('game/trader.tr_au_amount') }}:
                <input type="number" min="1" step="1" name="amount" value="" style="width: 110px">
                <select name="resource">
                    @foreach (['metal', 'crystal', 'deuterium'] as $resource)
                    <option value="{{ $resource }}">{{ __('game/global.' . $resource) }}</option>
                    @endforeach
                </select>
                <input type="submit" name="bid" value="{{ __('game/trader.tr_au_bid') }}">
            </form>
        </th>
    </tr>
    @endif
    @endif
</table>
