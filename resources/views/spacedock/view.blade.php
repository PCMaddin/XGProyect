@extends('master.game')

@section('content')
<x-notice width="519px" :color="$color" :message="$message" />

<table width="519px">
    <tr>
        <td class="c" colspan="3">{{ __('game/spacedock.sd_title') }}</td>
    </tr>
    <tr>
        <th colspan="3">
            @if ($dock > 0)
            {{ sprintf(__('game/spacedock.sd_level'), $dock, $share) }}
            @else
            {{ __('game/spacedock.sd_build_dock') }}
            @endif
        </th>
    </tr>
    @if ($field === null)
    <tr>
        <th colspan="3">{{ __('game/spacedock.sd_no_wreck_field') }}</th>
    </tr>
    @else
    <tr>
        <td class="c">{{ __('game/spacedock.sd_ship') }}</td>
        <td class="c">{{ __('game/spacedock.sd_wrecks') }}</td>
        <td class="c">{{ $field['repairing'] ? __('game/spacedock.sd_in_repair') : __('game/spacedock.sd_repairable') }}</td>
    </tr>
    @foreach ($field['rows'] as $row)
    <tr>
        <th>{{ $row['name'] }}</th>
        <th>{{ $row['wrecks'] }}</th>
        <th>{{ $row['repaired'] }}</th>
    </tr>
    @endforeach
    <tr>
        <th colspan="3">
            @if ($field['done'])
            {{ sprintf(__('game/spacedock.sd_repair_done'), $field['deploy_by']) }}
            <form method="POST" action="game.php?page=spacedock">
                @csrf
                <input type="hidden" name="action" value="deploy">
                <input type="submit" value="{{ __('game/spacedock.sd_deploy') }}">
            </form>
            @elseif ($field['repairing'])
            {{ sprintf(__('game/spacedock.sd_repair_until'), $field['ends']) }}
            @else
            @if ($field['waiting'])
            {{ sprintf(__('game/spacedock.sd_repairable_from'), $field['repairable_from']) }}<br>
            @endif
            {{ sprintf(__('game/spacedock.sd_burns_up'), $field['expires']) }}
            @if ($field['can_repair'])
            <form method="POST" action="game.php?page=spacedock">
                @csrf
                <input type="hidden" name="action" value="repair">
                <input type="submit" value="{{ __('game/spacedock.sd_repair') }}">
            </form>
            @endif
            @endif
        </th>
    </tr>
    @endif
</table>
@endsection
