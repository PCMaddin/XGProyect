@extends('operator.layout')

@section('title', class_basename($group->class))

@section('content')
<p><a href="{{ route('operator.errors') }}">← {{ __('operator/operator.op_errors') }}</a></p>
<h1 style="overflow-wrap:anywhere;">{{ $group->class }}</h1>
<div class="panel">
    <p style="white-space:pre-wrap;overflow-wrap:anywhere;" id="error-message">{{ $group->message }}</p>
    <table>
        <tr><th>{{ __('operator/operator.op_errors_place') }}</th><td><code>{{ $group->file }}:{{ $group->line }}</code></td></tr>
        <tr><th>{{ __('operator/operator.op_errors_times') }}</th><td>{{ number_format((int) $group->occurrences, 0, ',', '.') }}</td></tr>
        <tr><th>{{ __('operator/operator.op_errors_last') }}</th><td>{{ date('Y-m-d H:i:s', (int) $group->last_seen) }} · {{ $group->universe === '' ? '-' : $group->universe }} · {{ $group->context }}</td></tr>
        <tr><th>{{ __('operator/operator.op_errors_first') }}</th><td>{{ date('Y-m-d H:i:s', (int) $group->first_seen) }}</td></tr>
    </table>
    <form method="post" action="{{ route('operator.errors.update', $group->id) }}" class="actions">
        @csrf
        @foreach (\App\Services\Operators\ErrorMonitor::STATUSES as $option)
            <button type="submit" name="status" value="{{ $option }}" @class(['link' => $group->status === $option]) @disabled($group->status === $option)>{{ __('operator/operator.op_error_set_' . $option) }}</button>
        @endforeach
    </form>
</div>
<h2>{{ __('operator/operator.op_errors_trace') }}</h2>
<pre>{{ $group->trace }}</pre>
@endsection
