@extends('master.admin')

@section('content')
<div class="container-fluid">
    <x-alert/>

    <x-admin.page-header
        :title="__('admin/premium.pr_orders')"
        :subtitle="__('admin/premium.pr_orders_sub_title')"
    >
        <x-slot name="action">
            <a href="{{ route('admin.premium') }}" class="btn btn-secondary btn-icon-split">
                <span class="icon text-white-50"><i class="fas fa-arrow-left"></i></span>
                <span class="text">{{ __('admin/premium.pr_orders_back') }}</span>
            </a>
        </x-slot>
    </x-admin.page-header>

    <div class="row">
        <div class="col-lg-12">
            <x-admin.card :title="__('admin/premium.pr_orders_revenue')" icon="fas fa-euro-sign">
                <span class="h5 font-weight-bold text-gray-800" id="revenue">
                    {{ $revenue === [] ? '0' : implode(' + ', $revenue) }}
                </span>
            </x-admin.card>

            <x-admin.card
                :title="__('admin/premium.pr_orders_latest', ['count' => $shown])"
                icon="fas fa-receipt"
                :badge="count($orders)"
                :flush="true"
            >
                @if ($orders === [])
                    <x-admin.empty-state icon="fas fa-receipt" :message="__('admin/premium.pr_orders_none')" />
                @else
                    <div class="table-responsive">
                        <table class="table table-hover table-bordered mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>#</th>
                                    <th>{{ __('admin/premium.pr_orders_user') }}</th>
                                    <th>{{ __('admin/premium.pr_orders_package') }}</th>
                                    <th class="text-right">{{ __('admin/premium.pr_orders_dark_matter') }}</th>
                                    <th class="text-right">{{ __('admin/premium.pr_orders_amount') }}</th>
                                    <th>{{ __('admin/premium.pr_orders_status') }}</th>
                                    <th>{{ __('admin/premium.pr_orders_time') }}</th>
                                    <th>{{ __('admin/premium.pr_orders_paid') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($orders as $order)
                                    @php
                                        $badge = match ($order['status']) {
                                            'paid' => 'badge-success',
                                            'pending' => 'badge-warning',
                                            'refunded' => 'badge-danger',
                                            default => 'badge-secondary',
                                        };
                                    @endphp
                                    <tr>
                                        <td>{{ $order['id'] }}</td>
                                        <td>
                                            @if ($order['user_name'] !== null)
                                                <a href="{{ route('admin.users.premium', $order['user_id']) }}">{{ $order['user_name'] }}</a>
                                            @else
                                                <span class="text-muted">{{ __('admin/premium.pr_orders_deleted_user') }} ({{ $order['user_id'] }})</span>
                                            @endif
                                        </td>
                                        <td><code>{{ $order['package'] }}</code></td>
                                        <td class="text-right">{{ number_format($order['dark_matter'], 0, '', '.') }}</td>
                                        <td class="text-right">{{ $order['amount'] }}</td>
                                        <td><span class="badge {{ $badge }}">{{ __('admin/premium.pr_status_' . $order['status']) }}</span></td>
                                        <td>{{ $order['created_at'] }}</td>
                                        <td>{{ $order['paid_at'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-admin.card>
        </div>
    </div>
</div>
@endsection
