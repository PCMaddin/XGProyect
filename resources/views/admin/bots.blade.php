@extends('master.admin')

@section('content')
<div class="container-fluid">
    <x-alert/>
    <form action="{{ route('admin.bots.generate') }}" method="POST">
        @csrf
        <x-admin.page-header
            :title="__('admin/bots.bo_title')"
            :subtitle="__('admin/bots.bo_sub_title')"
        >
            <x-slot name="action">
                <button type="submit" class="btn btn-primary btn-icon-split">
                    <span class="icon text-white-50"><i class="fas fa-robot"></i></span>
                    <span class="text">{{ __('admin/bots.bo_generate') }}</span>
                </button>
            </x-slot>
        </x-admin.page-header>

        <div class="row">
            <div class="col-lg-6">
                <x-admin.card-collapsible id="collapseBots" :title="__('admin/bots.bo_parameters')" icon="fas fa-dice">
                    <x-admin.settings-table>
                        <tr>
                            <td>{{ __('admin/bots.bo_amount') }}</td>
                            <td>
                                <input class="form-control" type="number" name="amount"
                                    value="{{ old('amount', 1) }}" min="1" max="1000" required>
                            </td>
                        </tr>
                    </x-admin.settings-table>
                </x-admin.card-collapsible>
            </div>

            <div class="col-lg-6">
                <x-admin.card-collapsible id="collapseBotsRange" :title="__('admin/bots.bo_range')" icon="fas fa-arrows-alt-h">
                    <x-admin.settings-table>
                        <tr>
                            <td>{{ __('admin/bots.bo_galaxy_from') }}</td>
                            <td>
                                <input class="form-control" type="number" name="galaxy_from"
                                    value="{{ old('galaxy_from', 1) }}" min="1" max="{{ $max_galaxy }}">
                            </td>
                        </tr>
                        <tr>
                            <td>{{ __('admin/bots.bo_galaxy_to') }}</td>
                            <td>
                                <input class="form-control" type="number" name="galaxy_to"
                                    value="{{ old('galaxy_to', $max_galaxy) }}" min="1" max="{{ $max_galaxy }}">
                            </td>
                        </tr>
                    </x-admin.settings-table>
                </x-admin.card-collapsible>
            </div>
        </div>
    </form>

    <form action="{{ route('admin.bots.play') }}" method="POST">
        @csrf
        <div class="row">
            <div class="col-lg-6">
                <x-admin.card-collapsible id="collapseBotsPlay" :title="__('admin/bots.bo_play_title')" icon="fas fa-chess">
                    <p class="small text-gray-600">{{ __('admin/bots.bo_play_explanation') }}</p>
                    <x-admin.settings-table>
                        <tr>
                            <td><label for="bots_play">{{ __('admin/bots.bo_play_label') }}</label></td>
                            <td>
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input" id="bots_play"
                                        name="bots_play" {{ $bots_play ? 'checked' : '' }}>
                                    <label class="custom-control-label" for="bots_play"></label>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td>{{ __('admin/bots.bo_play_count') }}</td>
                            <td>{{ $bots_count }}</td>
                        </tr>
                    </x-admin.settings-table>
                    <button type="submit" class="btn btn-primary btn-sm">{{ __('admin/bots.bo_play_save') }}</button>
                </x-admin.card-collapsible>
            </div>
        </div>
    </form>
</div>
@endsection
