@extends('master.game')

@section('content')
<script type="text/javascript" src="{{ asset('assets/js/flotten-min.js') }}"></script>
<script type="text/javascript">
    function getStorageFaktor() {
        return 1
    }
    function returnValue(param, select) {
        /* By lucky - required for the new select box */
        var string = select.options[select.selectedIndex].value;
        if (string != 0) {
            var array = string.split(";");
            return array[param];
        } else {
            return null;
        }
    }

    // alliance classes: researchers fly expeditions, warriors fly to the
    // planets of their alliance's members 10 % faster (the fuel stays)
    var classSpeed = @json($class_speed);

    function classSpeedFactor() {
        var target = document.getElementsByName('galaxy')[0].value + ':' + document.getElementsByName('system')[0].value + ':' + document.getElementsByName('planet')[0].value;

        if (+document.getElementsByName('planet')[0].value === 16) {
            return +document.getElementsByName('planettype')[0].value === 2 ? 1 : classSpeed.expedition;
        }

        return classSpeed.allies.indexOf(target) >= 0 ? classSpeed.ally : 1;
    }

    var baseShortInfo = shortInfo;

    shortInfo = function () {
        baseShortInfo();

        var factor = classSpeedFactor();
        var note = document.getElementById('classspeed');

        note.innerHTML = '';

        if (factor <= 1) {
            return;
        }

        var seconds = Math.round((35e3 / speed() * Math.sqrt(10 * distance() / (maxspeed() * factor)) + 10) / document.getElementsByName('speedfactor')[0].value);
        var hours = Math.floor(seconds / 3600);
        var minutes = Math.floor((seconds % 3600) / 60);
        var rest = seconds % 60;

        document.getElementById('duration').innerHTML = hours + ':' + (minutes < 10 ? '0' : '') + minutes + ':' + (rest < 10 ? '0' : '') + rest + ' h';
        note.innerHTML = classSpeed.allies.length > 0 && +document.getElementsByName('planet')[0].value !== 16
            ? @json(__('game/fleet.fl_class_speed_ally'))
            : @json(__('game/fleet.fl_class_speed_expedition'));
    };
</script>
<form action="game.php?page=fleet3" method="post" onsubmit='this.submit.disabled = true;' role="form">
    @csrf
    @foreach ($fleet_block as $item)
        <input type="hidden" name="consumption{{ $item['ship_id'] }}" value="{{ $item['consumption'] }}" />
        <input type="hidden" name="speed{{ $item['ship_id'] }}" value="{{ $item['speed'] }}" />
        <input type="hidden" name="capacity{{ $item['ship_id'] }}" value="{{ $item['capacity'] }}" />
        <input type="hidden" name="ship{{ $item['ship_id'] }}" value="{{ $item['ship'] }}" />
    @endforeach
    <input type="hidden" name="speedfactor" value="{{ $speedfactor }}" />
    <input type="hidden" name="thisgalaxy" value="{{ $galaxy }}" />
    <input type="hidden" name="thissystem" value="{{ $system }}" />
    <input type="hidden" name="thisplanet" value="{{ $planet }}" />
    <input type="hidden" name="thisplanettype" value="{{ $planet_type }}" />
    <input type="hidden" name="target_mission" value="{{ $target_mission }}" />
    <table width="519" border="0" cellpadding="0" cellspacing="1">
        <tr height="20">
            <td colspan="2" class="c">{{ __('game/fleet.fl_send_fleet') }}</td>
        </tr>
        <tr height="20">
            <th scope="row" width="50%">{{ __('game/fleet.fl_destiny') }}</th>
            <th role="cell">
                <input name="galaxy" type="number" style="width: 37px" min="1" maxlength="2" onChange="shortInfo()" onKeyUp="shortInfo()" value="{{ $galaxy_end }}" />
                <input name="system" type="number" style="width: 40px" min="1" maxlength="3" onChange="shortInfo()" onKeyUp="shortInfo()" value="{{ $system_end }}" />
                <input name="planet" type="number" style="width: 37px" min="1" maxlength="2" onChange="shortInfo()" onKeyUp="shortInfo()" value="{{ $planet_end }}" />
                <select name="planettype" onChange="shortInfo()" onKeyUp="shortInfo()">
                    @foreach ($planet_types as $item)
                    <option value="{{ $item['value'] }}"{{ $item['selected'] }}>{{ $item['title'] }}</option>
                    @endforeach
                </select>
                <input name="fleet_group" type="hidden" onChange="shortInfo()" onKeyUp="shortInfo()" value="0" />
                <input name="acs_target" type="hidden" onChange="shortInfo()" onKeyUp="shortInfo()" value="0:0:0" />
            </th>
        </tr>
        <tr height="20">
            <th scope="row">{{ __('game/fleet.fl_fleet_speed') }}</th>
            <th role="cell">
                <select name="speed" onChange="shortInfo()" onKeyUp="shortInfo()">
                    @foreach ($speed_steps as $step)
                        <option value="{{ $step }}">{{ $step * 10 }}</option>
                    @endforeach
                </select> %
            </th>
        </tr>
        <tr height="20">
            <th scope="row">{{ __('game/fleet.fl_distance') }}</th>
            <th role="cell"><div id="distance">-</div></th>
        </tr>
        <tr height="20">
            <th scope="row">{{ __('game/fleet.fl_flying_time') }}</th>
            <th role="cell"><div id="duration">-</div><div id="classspeed" style="color: lime"></div></th>
        </tr>
        <tr height="20">
            <th scope="row">{{ __('game/fleet.fl_fuel_consumption') }}</th>
            <th role="cell"><div id="consumption">-</div></th>
        </tr>
        <tr height="20">
            <th scope="row">{{ __('game/fleet.fl_max_speed') }}</th>
            <th role="cell"><div id="maxspeed">-</div></th>
        </tr>
        <tr height="20">
            <th scope="row">{{ __('game/fleet.fl_cargo_capacity') }}</th>
            <th role="cell"><div id="storage">-</div></th>
        </tr>
        {!! $shortcuts !!}
        <tr height="20">
            <td colspan="2" class="c">{{ __('game/fleet.fl_my_planets') }}</td>
        </tr>
        {!! $colonies !!}
        </tr>
        <tr height="20">
            <td colspan="2" class="c">{{ __('game/fleet.fl_acs_title') }}</td>
        </tr>
        @foreach ($acs as $item)
            <tr height="20">
                <th role="cell" colspan="2">
                    <a href="javascript:setTarget({{ $item['galaxy'] }},{{ $item['system'] }},{{ $item['planet'] }},{{ $item['planet_type'] }}); shortInfo(); setACS({{ $item['id'] }}); setACS_target('g{{ $item['galaxy'] }}s{{ $item['system'] }}p{{ $item['planet'] }}t{{ $item['planet_type'] }}');">
                        ({{ $item['name'] }})
                    </a>
                </th>
            </tr>
            @endforeach
        <tr height="20">
            <th role="cell" colspan="2"><input type="submit" name="submit" value="{{ __('game/fleet.fl_continue') }}" /></th>
        </tr>
    </table>
</form>
<script>javascript:shortInfo();</script>
@endsection