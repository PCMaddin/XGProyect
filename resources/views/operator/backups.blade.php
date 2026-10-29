@extends('operator.layout')

@section('title', __('operator/operator.op_backups'))

@section('content')
<h1>{{ __('operator/operator.op_backups') }}</h1>
<div class="panel" id="backup-setup">
    @if ($configured)
        <p>{{ __('operator/operator.op_backups_target', ['repository' => $repository, 'time' => $time]) }}</p>
    @else
        <p><span class="badge warn">{{ __('operator/operator.op_backups_off') }}</span> {{ __('operator/operator.op_backups_off_help') }}</p>
    @endif
    <p class="help">{{ __('operator/operator.op_backups_databases') }}: <code>{{ implode(', ', $databases) }}</code></p>
    <p class="help">{{ __('operator/operator.op_backups_commands') }}</p>
    <pre>php artisan xgp:backup:run
php artisan xgp:backup:list
php artisan xgp:backup:restore &lt;database&gt; [--into=&lt;new database&gt;] [--snapshot=&lt;id&gt;]</pre>
</div>

<h2>{{ __('operator/operator.op_backups_runs') }}</h2>
<div class="panel">
    @if ($runs === [])
        <p>{{ __('operator/operator.op_backups_no_runs') }}</p>
    @else
        <table id="backup-runs">
            <thead>
                <tr>
                    <th>{{ __('operator/operator.op_col_time') }}</th>
                    <th>{{ __('operator/operator.op_col_status') }}</th>
                    <th>{{ __('operator/operator.op_backups_duration') }}</th>
                    <th>{{ __('operator/operator.op_backups_databases') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($runs as $run)
                    <tr>
                        <td>{{ date('Y-m-d H:i', (int) $run->started_at) }}</td>
                        <td>
                            <span class="badge {{ $run->status === 'ok' ? 'ok' : ($run->status === 'failed' ? 'warn' : 'muted') }}">{{ __('operator/operator.op_backup_status_' . $run->status) }}</span>
                            @if ($run->message !== '')
                                <div class="help" style="white-space:pre-wrap;overflow-wrap:anywhere;">{{ $run->message }}</div>
                            @endif
                        </td>
                        <td>{{ $run->finished_at === null ? '-' : gmdate('H:i:s', max(0, (int) $run->finished_at - (int) $run->started_at)) }}</td>
                        <td><code>{{ $run->databases }}</code></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
