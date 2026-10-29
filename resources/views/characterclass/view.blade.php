@extends('master.game')

@section('content')
<x-notice width="519px" :color="$color" :message="$message" />

<table width="665px">
    <tr>
        <td class="c" colspan="3">{{ __('game/characterclass.cc_title') }}</td>
    </tr>
    <tr>
        <th colspan="3">{{ __('game/characterclass.cc_explanation') }}<br>{{ $price }}</th>
    </tr>
    <tr>
        @foreach ($classes as $class)
        <th style="vertical-align: top; width: 33%; text-align: left">
            <h3 style="text-align: center; {{ $class['value'] === $current ? 'color: lime' : '' }}">{{ $class['name'] }}</h3>
            <ul style="padding-left: 16px">
                @foreach ($class['bonuses'] as $bonus)
                <li>{{ $bonus }}</li>
                @endforeach
            </ul>
            <div style="text-align: center">
                @if ($class['value'] === $current)
                <span style="color: lime">{{ __('game/characterclass.cc_active') }}</span>
                @else
                <form method="POST" action="game.php?page=characterclass">
                    @csrf
                    <input type="hidden" name="class" value="{{ $class['value'] }}">
                    <input type="submit" value="{{ __('game/characterclass.cc_choose') }}">
                </form>
                @endif
            </div>
        </th>
        @endforeach
    </tr>
</table>
@endsection
