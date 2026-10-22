@extends('operator.layout')

@section('title', __('operator/operator.op_versions'))

@section('content')
<h1>{{ __('operator/operator.op_versions') }}</h1>
<div class="panel">
    <p class="help">{{ __('operator/operator.op_versions_intro', ['path' => $root]) }}</p>
    <table id="versions">
        <thead>
            <tr>
                <th>{{ __('operator/operator.op_col_version') }}</th>
                <th>{{ __('operator/operator.op_col_status') }}</th>
                <th>{{ __('operator/operator.op_col_directory') }}</th>
                <th>{{ __('operator/operator.op_col_universes') }}</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td><code>{{ $row['version'] }}</code></td>
                    <td>
                        @if ($row['running'])
                            <span class="badge ok">{{ __('operator/operator.op_version_running') }}</span>
                        @elseif ($row['missing'])
                            <span class="badge warn">{{ __('operator/operator.op_version_missing') }}</span>
                        @endif
                    </td>
                    <td><code>{{ $row['directory'] }}</code></td>
                    <td>{{ implode(', ', $row['universes']) }}</td>
                    <td>
                        @if ($switchable && !$row['running'] && !$row['missing'])
                            <form method="post" action="{{ route('operator.versions.use') }}" style="display:inline">
                                @csrf
                                <input type="hidden" name="version" value="{{ $row['version'] }}">
                                <button type="submit" class="link" title="{{ __('operator/operator.op_use_help') }}">{{ __('operator/operator.op_use') }}</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <form method="post" action="{{ route('operator.versions.prune') }}" class="actions">
        @csrf
        <button type="submit" class="secondary">{{ __('operator/operator.op_prune') }}</button>
        <span class="help">{{ __('operator/operator.op_prune_help') }}</span>
    </form>
</div>

<h2>{{ __('operator/operator.op_fetch_title') }}</h2>
<form method="post" action="{{ route('operator.versions.fetch') }}" class="panel" id="fetch">
    @csrf
    @if ($fetching)
        <p><span class="badge warn">{{ __('operator/operator.op_fetch_running') }}</span></p>
    @endif
    <label for="ref">{{ __('operator/operator.op_fetch_ref') }} <span class="help">{{ __('operator/operator.op_fetch_ref_help') }}</span></label>
    <input type="text" id="ref" name="ref" value="{{ old('ref') }}" maxlength="200">
    <label for="repo">{{ __('operator/operator.op_fetch_repo') }} <span class="help">{{ __('operator/operator.op_fetch_repo_help') }}</span></label>
    <input type="text" id="repo" name="repo" value="{{ old('repo') }}" maxlength="500">
    <div class="actions">
        <button type="submit" @disabled($fetching)>{{ __('operator/operator.op_fetch') }}</button>
        <span class="help">{{ __('operator/operator.op_fetch_help') }}</span>
    </div>
    @if ($log !== '')
        <h3>{{ __('operator/operator.op_fetch_log') }}</h3>
        <pre id="fetch-log">{{ $log }}</pre>
    @endif
</form>

@if ($upgrades !== [])
<h2>{{ __('operator/operator.op_upgrade') }}</h2>
<div class="panel">
    <p class="help">{{ __('operator/operator.op_upgrade_help') }}</p>
    <table id="upgrades">
        <tbody>
            @foreach ($upgrades as $upgrade)
                <tr>
                    <td><strong>{{ $upgrade['instance']->name }}</strong><br><code>{{ $upgrade['instance']->slug }}</code></td>
                    <td><code>{{ $upgrade['instance']->version !== '' ? $upgrade['instance']->version : $current }}</code></td>
                    <td>
                        <form method="post" action="{{ route('operator.universes.upgrade', $upgrade['instance']->slug) }}">
                            @csrf
                            <label for="upgrade-{{ $upgrade['instance']->slug }}">{{ __('operator/operator.op_upgrade_to') }}</label>
                            <select id="upgrade-{{ $upgrade['instance']->slug }}" name="version">
                                @foreach ($upgrade['targets'] as $target)
                                    <option value="{{ $target }}" @selected($loop->last)>{{ $target }}</option>
                                @endforeach
                            </select>
                            <button type="submit">{{ __('operator/operator.op_upgrade') }}</button>
                        </form>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif
@endsection
