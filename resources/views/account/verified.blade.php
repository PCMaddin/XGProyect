@extends('account.plain')

@section('title', __('account/verify.vf_done_title'))

@section('content')
    <h1>{{ __('account/verify.vf_done_title') }}</h1>
    <section>
        <p>{{ __('account/verify.vf_done_text') }}</p>
    </section>
    <div class="links">
        <a href="{{ $game }}">{{ __('account/verify.vf_to_game') }}</a>
    </div>
@endsection
