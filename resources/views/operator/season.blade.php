@extends('operator.layout')

@section('title', __('operator/operator.op_season_title', ['name' => $instance->name]))

@section('content')
<h1>{{ __('operator/operator.op_season_title', ['name' => $instance->name]) }}</h1>
<p class="help"><a href="{{ route('operator.universes.edit', $instance->slug) }}">{{ __('operator/operator.op_edit') }}</a></p>
<div class="panel">
    @if ($prizes === [])
        <p>{{ $instance->isOpen() ? __('operator/operator.op_season_running') : __('operator/operator.op_prizes_none') }}</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>{{ __('operator/operator.op_col_category') }}</th>
                    <th>{{ __('operator/operator.op_col_rank') }}</th>
                    <th>{{ __('operator/operator.op_col_player') }}</th>
                    <th>{{ __('operator/operator.op_col_reward') }}</th>
                    <th>{{ __('operator/operator.op_col_wax') }}</th>
                    <th>{{ __('operator/operator.op_col_state') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($prizes as $prize)
                    <tr>
                        <td>{{ __('home/lobby.lb_category_' . $prize['category']) }}</td>
                        <td>{{ $prize['rank'] }}</td>
                        <td>{{ $prize['name'] }}</td>
                        <td>{{ number_format($prize['reward'], 0, ',', '.') }}</td>
                        <td>{{ $prize['wax_account'] === '' ? '-' : $prize['wax_account'] }}</td>
                        <td>
                            <span class="badge {{ $prize['state'] === 'open' ? 'muted' : ($prize['state'] === 'reserved' ? 'warn' : 'ok') }}">{{ __('operator/operator.op_state_' . $prize['state']) }}</span>
                            @if ($prize['universe'] !== '')<code>{{ $prize['universe'] }}</code>@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

<h2>{{ __('operator/operator.op_payout_title') }}</h2>
<div class="panel">
    @if ($token === null || $shop_account === '')
        <p>{{ __('operator/operator.op_no_token') }}</p>
    @elseif ($instance->isOpen())
        <p>{{ __('operator/operator.op_season_running') }}</p>
    @else
        <p>{{ __('operator/operator.op_payout_help', ['symbol' => $token->symbol]) }}</p>
        <div class="actions">
            <form method="post" action="{{ route('operator.universes.payout', $instance->slug) }}">@csrf<button type="submit">{{ __('operator/operator.op_payout_button') }}</button></form>
            @if ($reserved !== [])
                <form method="post" action="{{ route('operator.universes.release', $instance->slug) }}">@csrf<button type="submit" class="danger">{{ __('operator/operator.op_release_button') }}</button></form>
            @endif
        </div>
        @if ($reserved !== [])
            <p>{{ __('operator/operator.op_payout_actions', ['account' => $shop_account, 'count' => count($reserved)]) }}</p>
            <pre id="payout-actions">{{ json_encode(array_map(static fn (array $payout): array => [
                'account' => $token->contract,
                'name' => 'transfer',
                'authorization' => [['actor' => $shop_account, 'permission' => 'active']],
                'data' => ['from' => $shop_account, 'to' => $payout['to'], 'quantity' => $payout['quantity'], 'memo' => $payout['memo']],
            ], $reserved), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        @endif
    @endif
</div>
@endsection
