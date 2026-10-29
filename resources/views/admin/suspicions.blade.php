@extends('master.admin')

@section('content')
<div class="container-fluid">
    <x-alert/>

    <x-admin.page-header
        :title="__('admin/suspicions.su_title')"
        :subtitle="__('admin/suspicions.su_sub_title')"
    />

    <div class="row">
        <div class="col-lg-12">
            <x-admin.card :title="__('admin/suspicions.su_list')" icon="fas fa-user-secret" :badge="count($suspicions)" :flush="true">
                @if ($suspicions === [])
                    <x-admin.empty-state icon="fas fa-user-check" :message="__('admin/suspicions.su_none')" />
                @else
                    <div class="table-responsive">
                        <table class="table table-hover table-bordered mb-0" id="suspicions">
                            <thead class="thead-light">
                                <tr>
                                    <th>{{ __('admin/suspicions.su_player') }}</th>
                                    <th>{{ __('admin/suspicions.su_sign') }}</th>
                                    <th class="text-right">{{ __('admin/suspicions.su_score') }}</th>
                                    <th>{{ __('admin/suspicions.su_evidence') }}</th>
                                    <th>{{ __('admin/suspicions.su_seen') }}</th>
                                    <th>{{ __('admin/suspicions.su_decision') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($suspicions as $row)
                                    <tr>
                                        <td>
                                            <a href="{{ route('admin.users.info', $row['user_id']) }}">{{ $row['user'] }}</a>
                                            @if ($row['rank'] > 0 && $row['rank'] <= 10)
                                                <span class="badge badge-warning">{{ __('admin/suspicions.su_top_ten', ['rank' => $row['rank']]) }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            {{ __('admin/suspicions.su_kind_' . $row['kind']) }}
                                            @if ($row['other'] !== '')
                                                <br><small>{{ __('admin/suspicions.su_with', ['player' => $row['other']]) }}</small>
                                            @endif
                                        </td>
                                        <td class="text-right"><span class="badge {{ $row['score'] >= 80 ? 'badge-danger' : ($row['score'] >= 60 ? 'badge-warning' : 'badge-secondary') }}">{{ $row['score'] }}</span></td>
                                        <td><small>{{ $row['detail'] }}</small></td>
                                        <td><small>{{ $row['updated_at'] }}</small></td>
                                        <td>
                                            <form method="POST" action="{{ route('admin.suspicions.decide', ['suspicion' => $row['id']]) }}" class="form-inline" style="gap:6px;">
                                                @csrf
                                                <select name="status" class="form-control form-control-sm" aria-label="{{ __('admin/suspicions.su_decision') }}">
                                                    @foreach (['open', 'cleared', 'excluded'] as $status)
                                                        <option value="{{ $status }}" @selected($row['status'] === $status)>{{ __('admin/suspicions.su_status_' . $status) }}</option>
                                                    @endforeach
                                                </select>
                                                <input type="text" name="note" class="form-control form-control-sm" value="{{ $row['note'] }}" placeholder="{{ __('admin/suspicions.su_note') }}" maxlength="500" aria-label="{{ __('admin/suspicions.su_note') }}">
                                                <button type="submit" class="btn btn-sm btn-primary">{{ __('admin/suspicions.su_save') }}</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="px-3 pt-3 small text-muted">{{ __('admin/suspicions.su_help') }}</p>
                @endif
            </x-admin.card>
        </div>
    </div>
</div>
@endsection
