<x-mail::message>
# {{ __('account/verify.vf_mail_title', ['game' => $gameName]) }}

{{ __('account/verify.vf_mail_text', ['name' => $userName]) }}

<x-mail::button :url="$link">
{{ __('account/verify.vf_mail_button') }}
</x-mail::button>

{{ __('account/verify.vf_mail_deadline', ['date' => date('Y-m-d H:i T', $deadline)]) }}

<small>{{ __('account/verify.vf_mail_not_you') }}</small>
</x-mail::message>
