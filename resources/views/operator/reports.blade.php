@extends('operator.layout')

@section('title', __('operator/operator.op_reports'))

@section('content')
<h1>{{ __('operator/operator.op_reports') }}</h1>
<p class="actions" id="report-filters">
    @foreach (\App\Services\Feedback\BugReports::STATUSES as $filter)
        <a href="{{ route('operator.reports', ['status' => $filter]) }}" @if ($status === $filter) aria-current="page" style="font-weight:600;" @endif>
            {{ __('operator/operator.op_report_status_' . $filter) }} ({{ $counts[$filter] }})
        </a>
    @endforeach
    <a href="{{ route('operator.reports', ['status' => 'all']) }}" @if ($status === null) aria-current="page" style="font-weight:600;" @endif>{{ __('operator/operator.op_reports_all') }} ({{ array_sum($counts) }})</a>
</p>
<div class="panel">
    @if ($reports === [])
        <p>{{ __('operator/operator.op_reports_none') }}</p>
    @else
        <table id="reports">
            <thead>
                <tr>
                    <th>{{ __('operator/operator.op_col_time') }}</th>
                    <th>{{ __('operator/operator.op_reports_from') }}</th>
                    <th>{{ __('operator/operator.op_reports_text') }}</th>
                    <th>{{ __('operator/operator.op_col_status') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($reports as $report)
                    <tr>
                        <td>{{ date('Y-m-d H:i', (int) $report->created_at) }}</td>
                        <td>
                            <strong>{{ $report->user_name }}</strong> (#{{ $report->user_id }})<br>
                            <code>{{ $report->universe === '' ? '-' : $report->universe }}</code><br>
                            <span class="help">{{ $report->page }}</span>
                        </td>
                        <td>
                            <div style="white-space:pre-wrap;overflow-wrap:anywhere;">{{ $report->description }}</div>
                            <div class="help">{{ $report->user_agent }}</div>
                        </td>
                        <td>
                            <form method="post" action="{{ route('operator.reports.update', $report->id) }}">
                                @csrf
                                <select name="status" aria-label="{{ __('operator/operator.op_col_status') }}">
                                    @foreach (\App\Services\Feedback\BugReports::STATUSES as $option)
                                        <option value="{{ $option }}" @selected($report->status === $option)>{{ __('operator/operator.op_report_status_' . $option) }}</option>
                                    @endforeach
                                </select>
                                <div class="actions" style="margin-top:6px;"><button type="submit">{{ __('operator/operator.op_reports_save') }}</button></div>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="actions">
            @if ($page > 0)
                <a href="{{ route('operator.reports', ['status' => $status ?? 'all', 'page' => $page - 1]) }}">← {{ __('operator/operator.op_reports_newer') }}</a>
            @endif
            @if (count($reports) === \App\Services\Feedback\BugReports::PAGE_SIZE)
                <a href="{{ route('operator.reports', ['status' => $status ?? 'all', 'page' => $page + 1]) }}">{{ __('operator/operator.op_reports_older') }} →</a>
            @endif
        </div>
    @endif
</div>
@endsection
