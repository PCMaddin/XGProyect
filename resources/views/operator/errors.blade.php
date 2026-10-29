@extends('operator.layout')

@section('title', __('operator/operator.op_errors'))

@section('content')
<h1>{{ __('operator/operator.op_errors') }}</h1>
<p class="help">{{ $mail === '' ? __('operator/operator.op_errors_no_mail') : __('operator/operator.op_errors_mail_to', ['mail' => $mail]) }}</p>
<p class="actions" id="error-filters">
    @foreach (\App\Services\Operators\ErrorMonitor::STATUSES as $filter)
        <a href="{{ route('operator.errors', ['status' => $filter]) }}" @if ($status === $filter) aria-current="page" style="font-weight:600;" @endif>
            {{ __('operator/operator.op_error_status_' . $filter) }} ({{ $counts[$filter] }})
        </a>
    @endforeach
    <a href="{{ route('operator.errors', ['status' => 'all']) }}" @if ($status === null) aria-current="page" style="font-weight:600;" @endif>{{ __('operator/operator.op_reports_all') }} ({{ array_sum($counts) }})</a>
</p>
<div class="panel">
    @if ($groups === [])
        <p>{{ __('operator/operator.op_errors_none') }}</p>
    @else
        <table id="errors">
            <thead>
                <tr>
                    <th>{{ __('operator/operator.op_errors_last') }}</th>
                    <th>{{ __('operator/operator.op_errors_error') }}</th>
                    <th>{{ __('operator/operator.op_errors_times') }}</th>
                    <th>{{ __('operator/operator.op_col_status') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($groups as $group)
                    <tr>
                        <td>{{ date('Y-m-d H:i', (int) $group->last_seen) }}<br><span class="help">{{ __('operator/operator.op_errors_since', ['date' => date('Y-m-d', (int) $group->first_seen)]) }}</span></td>
                        <td>
                            <a href="{{ route('operator.errors.show', $group->id) }}"><strong>{{ class_basename($group->class) }}</strong></a>
                            <div style="overflow-wrap:anywhere;">{{ \Illuminate\Support\Str::limit($group->message, 240) }}</div>
                            <div class="help"><code>{{ $group->file }}:{{ $group->line }}</code> · {{ $group->universe === '' ? '-' : $group->universe }} · {{ $group->context }}</div>
                        </td>
                        <td>{{ number_format((int) $group->occurrences, 0, ',', '.') }}</td>
                        <td><span class="badge {{ $group->status === 'open' ? 'warn' : 'muted' }}">{{ __('operator/operator.op_error_status_' . $group->status) }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="actions">
            @if ($page > 0)
                <a href="{{ route('operator.errors', ['status' => $status ?? 'all', 'page' => $page - 1]) }}">← {{ __('operator/operator.op_reports_newer') }}</a>
            @endif
            @if (count($groups) === \App\Services\Operators\ErrorMonitor::PAGE_SIZE)
                <a href="{{ route('operator.errors', ['status' => $status ?? 'all', 'page' => $page + 1]) }}">{{ __('operator/operator.op_reports_older') }} →</a>
            @endif
        </div>
    @endif
</div>
@endsection
