@extends('master.game')

@section('content')
<x-notice width="519px" :color="$color" :message="$message" />

<table width="665px">
    <tr>
        <td class="c" colspan="3">{{ __('game/alliance.al_class_title') }}</td>
    </tr>
    <tr>
        <th colspan="3">{{ __('game/alliance.al_class_explanation') }}<br>{{ $price }}</th>
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
                <span style="color: lime">{{ __('game/alliance.al_class_active') }}</span>
                @else
                <form method="POST" action="game.php?page=alliance&mode=admin&edit=class">
                    @csrf
                    <input type="hidden" name="class" value="{{ $class['value'] }}">
                    <input type="submit" value="{{ __('game/alliance.al_class_choose') }}">
                </form>
                @endif
            </div>
        </th>
        @endforeach
    </tr>
    <tr>
        <th colspan="3"><a href="game.php?page=alliance&mode=admin&edit=ally">{{ __('game/alliance.al_back') }}</a></th>
    </tr>
</table>
@endsection
