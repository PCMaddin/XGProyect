@extends('account.plain')

@section('title', __('account/privacy.pv_deleted_title'))

@section('content')
    <h1>{{ __('account/privacy.pv_deleted_title') }}</h1>
    <section>
        <p>{{ __('account/privacy.pv_deleted_text') }}</p>
    </section>
    <div class="links">
        <a href="{{ url('/') }}">{{ __('account/privacy.pv_to_home') }}</a>
    </div>
@endsection
