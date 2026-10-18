@extends('operator.layout')

@section('title', __('operator/operator.op_universes'))

@section('content')
<h1>{{ __('operator/operator.op_universes') }}</h1>
<div class="panel">
    @if ($universes === [])
        <p>{{ __('operator/operator.op_none') }}</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>{{ __('operator/operator.op_col_name') }}</th>
                    <th>{{ __('operator/operator.op_col_mode') }}</th>
                    <th>{{ __('operator/operator.op_col_status') }}</th>
                    <th>{{ __('operator/operator.op_col_opened') }}</th>
                    <th>{{ __('operator/operator.op_col_ends') }}</th>
                    <th>{{ __('operator/operator.op_col_rewards') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($universes as $universe)
                    @php($instance = $universe['instance'])
                    <tr>
                        <td><strong>{{ $instance->name }}</strong><br><code>{{ $instance->slug }}</code></td>
                        <td>{{ __('operator/operator.op_mode_' . $instance->mode) }}</td>
                        <td>
                            @if ($instance->isOpen())
                                <span class="badge ok">{{ __('operator/operator.op_status_open') }}</span>
                            @elseif ($instance->status === 'open')
                                <span class="badge warn">{{ __('operator/operator.op_status_ended') }}</span>
                            @else
                                <span class="badge muted">{{ __('operator/operator.op_status_closed') }}</span>
                            @endif
                        </td>
                        <td>{{ date('Y-m-d', $instance->openedAt) }}</td>
                        <td>{{ $instance->endsAt === null ? __('operator/operator.op_no_end') : date('Y-m-d H:i', $instance->endsAt) }}</td>
                        <td>{{ $universe['rewards'] === null || $universe['rewards']->isEmpty() ? '-' : number_format(array_sum($universe['rewards']->pools), 0, ',', '.') }}</td>
                        <td>
                            <a href="{{ route('operator.universes.edit', $instance->slug) }}">{{ __('operator/operator.op_edit') }}</a> ·
                            <a href="{{ route('operator.universes.season', $instance->slug) }}">{{ __('operator/operator.op_season') }}</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
    <div class="actions"><a class="button" href="{{ route('operator.universes.create') }}">{{ __('operator/operator.op_new_universe') }}</a></div>
</div>

<h2>{{ __('operator/operator.op_events') }}</h2>
<div class="panel">
    <table>
        <thead>
            <tr>
                <th>{{ __('operator/operator.op_col_time') }}</th>
                <th>{{ __('operator/operator.op_col_operator') }}</th>
                <th>{{ __('operator/operator.op_col_action') }}</th>
                <th>{{ __('operator/operator.op_col_subject') }}</th>
                <th>{{ __('operator/operator.op_col_ip') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($events as $event)
                <tr>
                    <td>{{ date('Y-m-d H:i:s', $event['created_at']) }}</td>
                    <td>{{ $event['email'] }}</td>
                    <td>{{ __('operator/operator.op_action_' . $event['action']) }}</td>
                    <td>{{ $event['subject'] }}</td>
                    <td><code>{{ $event['ip'] }}</code></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
