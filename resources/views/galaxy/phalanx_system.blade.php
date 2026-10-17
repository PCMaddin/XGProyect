<font color="red">{{ $error }}</font>
<table width="519">
    <tr>
        <td class=c colspan=4>{{ __('game/phalanx.px_system_phalanx') }} [{{ $galaxy }}:{{ $system }}]</td>
    </tr>
    @foreach ($positions as $position)
    <tr>
        <td class=c colspan=4>{{ __('game/phalanx.px_scan_position') }} [{{ $galaxy }}:{{ $system }}:{{ $position['planet'] }}] ({{ $position['name'] }})</td>
    </tr>
    @if ($position['fleets'] === '')
    <tr>
        <th colspan=4>{{ __('game/phalanx.px_no_movements') }}</th>
    </tr>
    @else
    {!! $position['fleets'] !!}
    @endif
    @endforeach
</table>
