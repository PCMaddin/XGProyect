<x-mail::message>
# {{ __('operator/operator.op_errors_mail_title') }}

@foreach ($groups as $group)
**{{ $group->class }}** · {{ __('operator/operator.op_errors_mail_times', ['count' => $group->pending]) }} · {{ $group->universe === '' ? '-' : $group->universe }}

{{ \Illuminate\Support\Str::limit(\App\Services\Operators\ErrorMonitor::redact($group->message), 300) }}

`{{ $group->file }}:{{ $group->line }}` · {{ $group->context }}

[{{ __('operator/operator.op_errors_open') }}]({{ $base }}/operator/errors/{{ $group->id }})

---
@endforeach
</x-mail::message>
