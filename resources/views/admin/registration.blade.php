@extends('master.admin')

@section('content')
<div class="container-fluid">
    <x-alert/>
    <form action="{{ route('admin.registration.update') }}" method="POST">
        @csrf
        <x-admin.page-header
            :title="__('admin/registration.ur_title')"
            :subtitle="__('admin/registration.ur_sub_title')"
        >
            <x-slot name="action">
                <button type="submit" class="btn btn-primary btn-icon-split">
                    <span class="icon text-white-50"><i class="fas fa-save"></i></span>
                    <span class="text">{{ __('admin/registration.ur_save_parameters') }}</span>
                </button>
            </x-slot>
        </x-admin.page-header>

        <div class="row">
            <div class="col-lg-6">
                <x-admin.card-collapsible id="collapseGeneral" :title="__('admin/registration.ur_general')" icon="fas fa-user-plus">
                    <x-admin.settings-table>
                        <tr>
                            <td>{{ __('admin/registration.ur_open_close') }}</td>
                            <td>
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input" id="reg_enable"
                                        name="reg_enable" {{ $reg_enable ? 'checked' : '' }}>
                                    <label class="custom-control-label" for="reg_enable"></label>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td>{{ __('admin/registration.ur_invite_only') }}<br><small class="text-muted">{{ __('admin/registration.ur_invite_only_help') }}</small></td>
                            <td>
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input" id="reg_invite_only"
                                        name="reg_invite_only" {{ $reg_invite_only ? 'checked' : '' }}>
                                    <label class="custom-control-label" for="reg_invite_only"></label>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td>{{ __('admin/registration.ur_max_players') }}<br><small class="text-muted">{{ __('admin/registration.ur_max_players_help') }}</small></td>
                            <td>
                                <input class="form-control" type="number" min="0" name="reg_max_players" id="reg_max_players" value="{{ $reg_max_players }}">
                            </td>
                        </tr>
                    </x-admin.settings-table>
                </x-admin.card-collapsible>
            </div>

            <div class="col-lg-6">
                <x-admin.card-collapsible id="collapseWelcome" :title="__('admin/registration.ur_section_welcome')" icon="fas fa-envelope-open-text">
                    <x-admin.settings-table>
                        <tr>
                            <td>{{ __('admin/registration.ur_welcome_message') }}</td>
                            <td>
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input" id="reg_welcome_message"
                                        name="reg_welcome_message" {{ $reg_welcome_message ? 'checked' : '' }}>
                                    <label class="custom-control-label" for="reg_welcome_message"></label>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td>{{ __('admin/registration.ur_welcome_email') }}</td>
                            <td>
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input" id="reg_welcome_email"
                                        name="reg_welcome_email" {{ $reg_welcome_email ? 'checked' : '' }}>
                                    <label class="custom-control-label" for="reg_welcome_email"></label>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td>{{ __('admin/registration.ur_verify_email') }}<br><small class="text-muted">{{ __('admin/registration.ur_verify_email_help') }}</small></td>
                            <td>
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input" id="reg_verify_email"
                                        name="reg_verify_email" {{ $reg_verify_email ? 'checked' : '' }}>
                                    <label class="custom-control-label" for="reg_verify_email"></label>
                                </div>
                            </td>
                        </tr>
                    </x-admin.settings-table>
                </x-admin.card-collapsible>
            </div>
        </div>
    </form>

    <div class="row">
        <div class="col-12">
            <x-admin.card-collapsible id="collapseInvitations" :title="__('admin/registration.ur_invitations')" icon="fas fa-ticket-alt">
                <form action="{{ route('admin.registration.invite') }}" method="POST" class="form-inline mb-3" id="invite-form">
                    @csrf
                    <label class="mr-2" for="invite-uses">{{ __('admin/registration.ur_invite_uses') }}</label>
                    <input class="form-control mr-3" type="number" min="1" max="10000" id="invite-uses" name="uses" value="1" required style="width:90px">
                    <label class="mr-2" for="invite-days">{{ __('admin/registration.ur_invite_days') }}</label>
                    <input class="form-control mr-3" type="number" min="1" max="365" id="invite-days" name="days" placeholder="∞" style="width:90px">
                    <label class="mr-2" for="invite-note">{{ __('admin/registration.ur_invite_note') }}</label>
                    <input class="form-control mr-3" type="text" maxlength="100" id="invite-note" name="note">
                    <button type="submit" class="btn btn-primary btn-sm">{{ __('admin/registration.ur_invite_create') }}</button>
                </form>
                @if ($invitations === [])
                    <p class="text-muted mb-0">{{ __('admin/registration.ur_invite_none') }}</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm" id="invitations">
                            <thead>
                                <tr>
                                    <th>{{ __('admin/registration.ur_invite_code') }}</th>
                                    <th>{{ __('admin/registration.ur_invite_used') }}</th>
                                    <th>{{ __('admin/registration.ur_invite_expires') }}</th>
                                    <th>{{ __('admin/registration.ur_invite_note') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($invitations as $invitation)
                                    <tr @class(['text-muted' => $invitation['used'] >= $invitation['uses'] || ($invitation['expires_at'] !== null && $invitation['expires_at'] < time())])>
                                        <td><code>{{ $invitation['code'] }}</code><br><small><a href="{{ $invitation['link'] }}" target="_blank" rel="noopener">{{ $invitation['link'] }}</a></small></td>
                                        <td>{{ $invitation['used'] }} / {{ $invitation['uses'] }}</td>
                                        <td>{{ $invitation['expires_at'] === null ? '–' : date('Y-m-d H:i', $invitation['expires_at']) }}</td>
                                        <td>{{ $invitation['note'] }}</td>
                                        <td>
                                            <form action="{{ route('admin.registration.revoke', ['invitation' => $invitation['id']]) }}" method="POST">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-outline-danger btn-sm">{{ __('admin/registration.ur_invite_revoke') }}</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-admin.card-collapsible>
        </div>
    </div>
</div>
@endsection