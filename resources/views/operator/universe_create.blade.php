@extends('operator.layout')

@section('title', __('operator/operator.op_create_title'))

@section('content')
<h1>{{ __('operator/operator.op_create_title') }}</h1>
<form method="post" action="{{ route('operator.universes.store') }}" class="panel">
    @csrf
    <label for="slug">{{ __('operator/operator.op_slug') }} <span class="help">{{ __('operator/operator.op_slug_help') }}</span></label>
    <input type="text" id="slug" name="slug" value="{{ old('slug') }}" pattern="[a-z0-9][a-z0-9-]{0,31}" required>
    <label for="name">{{ __('operator/operator.op_name') }}</label>
    <input type="text" id="name" name="name" value="{{ old('name') }}" maxlength="64" required>
    <label for="mode">{{ __('operator/operator.op_mode') }}</label>
    <select id="mode" name="mode">
        @foreach (['pay2win', 'play2win'] as $mode)
            <option value="{{ $mode }}" @selected(old('mode', 'pay2win') === $mode)>{{ __('operator/operator.op_mode_' . $mode) }}</option>
        @endforeach
    </select>
    <label>{{ __('operator/operator.op_payments') }} <span class="help">{{ __('operator/operator.op_payments_help') }}</span></label>
    <div class="checks">
        @foreach ($methods as $method)
            <label><input type="checkbox" name="payments[]" value="{{ $method }}" @checked(in_array($method, old('payments', []), true))> {{ __('operator/operator.op_method_' . $method) }}</label>
        @endforeach
    </div>
    <label for="ends">{{ __('operator/operator.op_ends') }} <span class="help">{{ __('operator/operator.op_ends_help') }}</span></label>
    <input type="datetime-local" id="ends" name="ends" value="{{ old('ends') }}">
    <label for="bots">{{ __('operator/operator.op_bots') }}</label>
    <input type="number" id="bots" name="bots" value="{{ old('bots', 0) }}" min="0" max="1000">

    <h2>{{ __('operator/operator.op_admin') }}</h2>
    <p class="help">{{ __('operator/operator.op_admin_help') }}</p>
    <label for="admin_name">{{ __('operator/operator.op_admin_name') }}</label>
    <input type="text" id="admin_name" name="admin_name" value="{{ old('admin_name', 'Admin') }}" maxlength="64" required>
    <label for="admin_email">{{ __('operator/operator.op_admin_email') }}</label>
    <input type="email" id="admin_email" name="admin_email" value="{{ old('admin_email', $admin_email) }}" required>
    <label for="admin_password">{{ __('operator/operator.op_admin_password') }}</label>
    <input type="password" id="admin_password" name="admin_password" autocomplete="new-password" minlength="8" required>
    <div class="actions">
        <button type="submit">{{ __('operator/operator.op_create') }}</button>
        <span class="help">{{ __('operator/operator.op_create_wait') }}</span>
    </div>
</form>
@endsection
