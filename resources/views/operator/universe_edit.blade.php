@extends('operator.layout')

@section('title', $instance->name)

@section('content')
<h1>{{ $instance->name }} <code>{{ $instance->slug }}</code></h1>
<p class="help">{{ __('operator/operator.op_database') }}: <code>{{ $instance->database }}</code> · {{ __('operator/operator.op_col_opened') }}: {{ date('Y-m-d H:i', $instance->openedAt) }} · <a href="{{ route('operator.universes.season', $instance->slug) }}">{{ __('operator/operator.op_season') }}</a></p>
<form method="post" action="{{ route('operator.universes.update', $instance->slug) }}" class="panel">
    @csrf
    <label for="name">{{ __('operator/operator.op_name') }}</label>
    <input type="text" id="name" name="name" value="{{ old('name', $instance->name) }}" maxlength="64" required>
    <label for="mode">{{ __('operator/operator.op_mode') }}</label>
    <select id="mode" name="mode">
        @foreach (['pay2win', 'play2win'] as $mode)
            <option value="{{ $mode }}" @selected(old('mode', $instance->mode) === $mode)>{{ __('operator/operator.op_mode_' . $mode) }}</option>
        @endforeach
    </select>
    <label for="status">{{ __('operator/operator.op_status') }} <span class="help">{{ __('operator/operator.op_status_help') }}</span></label>
    <select id="status" name="status">
        @foreach (['open', 'closed'] as $status)
            <option value="{{ $status }}" @selected(old('status', $instance->status) === $status)>{{ __('operator/operator.op_status_' . $status) }}</option>
        @endforeach
    </select>
    <label>{{ __('operator/operator.op_payments') }} <span class="help">{{ __('operator/operator.op_payments_help') }}</span></label>
    <div class="checks">
        @foreach ($methods as $method)
            <label>
                <input type="checkbox" name="payments[]" value="{{ $method['name'] }}" @checked(in_array($method['name'], old('payments', $payments), true))>
                {{ __('operator/operator.op_method_' . $method['name']) }}
                @unless ($method['ready'])<span class="badge warn">{{ __('operator/operator.op_method_missing') }}</span>@endunless
            </label>
        @endforeach
    </div>
    <label for="ends">{{ __('operator/operator.op_ends') }} <span class="help">{{ __('operator/operator.op_ends_help') }}</span></label>
    <input type="datetime-local" id="ends" name="ends" value="{{ old('ends', $instance->endsAt === null ? '' : date('Y-m-d\TH:i', $instance->endsAt)) }}">
    @php($pools = [])
    @foreach ($plan->pools as $category => $amount)
        @php($pools[] = $category . '=' . $amount)
    @endforeach
    <label for="rewards">{{ __('operator/operator.op_rewards') }} <span class="help">{{ __('operator/operator.op_rewards_help', ['categories' => implode(', ', $categories)]) }}</span></label>
    <input type="text" id="rewards" name="rewards" value="{{ old('rewards', implode(',', $pools)) }}">
    <label for="shares">{{ __('operator/operator.op_shares') }} <span class="help">{{ __('operator/operator.op_shares_help') }}</span></label>
    <input type="text" id="shares" name="shares" value="{{ old('shares', implode(',', $plan->shares)) }}">
    <div class="actions"><button type="submit">{{ __('operator/operator.op_save') }}</button></div>
</form>

@if ($instance->status === 'open')
    <form method="post" action="{{ route('operator.universes.close', $instance->slug) }}" class="panel" onsubmit="return confirm(this.dataset.confirm)" data-confirm="{{ __('operator/operator.op_close_confirm', ['name' => $instance->name]) }}">
        @csrf
        <p>{{ __('operator/operator.op_close_help') }}</p>
        <button type="submit" class="danger">{{ __('operator/operator.op_close') }}</button>
    </form>
@endif
@endsection
