@extends('operator.layout')

@section('title', __('operator/operator.op_legal'))

@section('content')
<h1>{{ __('operator/operator.op_legal') }}</h1>
<p class="help">{{ __('operator/operator.op_legal_help') }}</p>
<div class="panel">
    <table>
        <thead>
            <tr>
                <th>{{ __('operator/operator.op_legal_page') }}</th>
                @foreach ($languages as $language)
                    <th>{{ $language }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($overview as $page => $texts)
                <tr>
                    <td>
                        {{ __('home/legal.lg_' . $page) }}
                        @if ($texts !== [])
                            <br><a class="help" href="{{ route('legal.show', $page) }}" target="_blank" rel="noopener">/legal/{{ $page }}</a>
                        @else
                            <br><span class="badge warn">{{ __('operator/operator.op_legal_missing') }}</span>
                        @endif
                    </td>
                    @foreach ($languages as $language)
                        <td>
                            <a href="{{ route('operator.legal.edit', ['page' => $page, 'locale' => $language]) }}"
                               title="{{ isset($texts[$language]) ? date('Y-m-d H:i', $texts[$language]['updated_at']) . ' · ' . $texts[$language]['updated_by'] : '' }}">
                                @if (isset($texts[$language]))
                                    <span class="badge ok">✓</span>
                                @else
                                    <span class="badge muted">+</span>
                                @endif
                            </a>
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
