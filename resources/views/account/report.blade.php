@extends('account.plain')

@section('title', __('account/report.rp_title'))

@section('content')
    <h1>{{ __('account/report.rp_title') }}</h1>

    @if (session('report_sent'))
        <div class="note success" role="status">{{ __('account/report.rp_sent') }}</div>
    @endif
    @if ($errors->any())
        <div class="note error" role="alert">{{ $errors->first() }}</div>
    @endif

    <p class="lead">{{ __('account/report.rp_lead') }}</p>

    <section>
        <form method="POST" action="{{ route('account.report.store') }}">
            @csrf
            <input type="hidden" name="from" value="{{ $from }}">
            @if ($from !== '')
                <p class="lead">{{ __('account/report.rp_page') }}: <code>{{ $from }}</code></p>
            @endif
            <label for="description">{{ __('account/report.rp_description') }}</label>
            <textarea id="description" name="description" rows="8" minlength="10" maxlength="{{ \App\Services\Feedback\BugReports::MAX_LENGTH }}" required>{{ old('description') }}</textarea>
            <p class="lead" style="font-size:13px;">{{ __('account/report.rp_hint') }}</p>
            <div><button type="submit">{{ __('account/report.rp_send') }}</button></div>
        </form>
    </section>

    <div class="links">
        <a href="{{ url($from !== '' ? $from : 'game.php?page=overview') }}">{{ __('account/report.rp_back') }}</a>
    </div>
@endsection
