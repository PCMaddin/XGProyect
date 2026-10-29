@extends('master.game')

@php($tierColors = ['bronze' => '#cd7f32', 'silver' => '#c0c0c0', 'gold' => '#ffd700'])

@section('content')
<x-notice width="519px" :color="$color" :message="$message" />

<table width="665px">
    <tr>
        <td class="c" colspan="3">{{ __('game/items.it_inventory') }}</td>
    </tr>
    @forelse ($inventory as $item)
    <tr>
        <th style="text-align:left; width: 180px">
            <span style="color: {{ $tierColors[$item['tier']] }}">{{ $item['name'] }}</span><br>
            {{ __('game/items.it_amount') }}: {{ $item['amount'] }}
        </th>
        <th style="text-align:left">{{ $item['description'] }}</th>
        <th style="width: 110px">
            <form method="POST" action="game.php?page=items">
                @csrf
                <input type="hidden" name="item" value="{{ $item['key'] }}">
                <input type="submit" name="use" value="{{ __('game/items.it_use') }}">
            </form>
        </th>
    </tr>
    @empty
    <tr>
        <th colspan="3">{{ __('game/items.it_none') }}</th>
    </tr>
    @endforelse
    @if ($boosters !== [])
    <tr>
        <td class="c" colspan="3">{{ __('game/items.it_active_boosters') }}</td>
    </tr>
    @foreach ($boosters as $booster)
    <tr>
        <th colspan="3" style="text-align:left">{{ $booster['text'] }}</th>
    </tr>
    @endforeach
    @endif
</table>
<br>
<table width="665px">
    <tr>
        <td class="c" colspan="3">{{ __('game/items.it_shop') }}</td>
    </tr>
    @foreach ($shop as $item)
    <tr>
        <th style="text-align:left; width: 180px">
            <span style="color: {{ $tierColors[$item['tier']] }}">{{ $item['name'] }}</span>
        </th>
        <th style="text-align:left">{{ $item['description'] }}</th>
        <th style="width: 110px">
            <form method="POST" action="game.php?page=items">
                @csrf
                <input type="hidden" name="item" value="{{ $item['key'] }}">
                {{ $item['price'] }} {{ __('game/global.dark_matter_short') }}<br>
                <input type="submit" name="buy" value="{{ __('game/items.it_buy') }}">
            </form>
        </th>
    </tr>
    @endforeach
</table>
@endsection
