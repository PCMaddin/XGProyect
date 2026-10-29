@extends('operator.layout')

@section('title', __('home/legal.lg_' . $page) . ' (' . $locale . ')')

@section('content')
<h1>{{ __('home/legal.lg_' . $page) }} · {{ $locale }}</h1>
<p><a href="{{ route('operator.legal') }}">← {{ __('operator/operator.op_legal') }}</a></p>
<form method="post" action="{{ route('operator.legal.update', ['page' => $page, 'locale' => $locale]) }}" class="panel">
    @csrf
    <label for="body">{{ __('operator/operator.op_legal_text') }}</label>
    <p class="help">{{ __('operator/operator.op_legal_markdown') }}</p>
    <textarea id="body" name="body" maxlength="{{ \App\Services\Legal\LegalPages::MAX_LENGTH }}" spellcheck="true">{{ $body }}</textarea>
    <div class="actions">
        <button type="submit" name="action" value="save">{{ __('operator/operator.op_legal_save') }}</button>
        <button type="submit" name="action" value="preview" class="link">{{ __('operator/operator.op_legal_preview') }}</button>
        <span class="help">{{ __('operator/operator.op_legal_empty_removes') }}</span>
    </div>
</form>
@if ($preview !== '')
    <h2>{{ __('operator/operator.op_legal_preview') }}@if ($unsaved) <span class="badge warn">{{ __('operator/operator.op_legal_unsaved') }}</span>@endif</h2>
    <div class="panel preview" id="preview">{!! $preview !!}</div>
@endif
@endsection
