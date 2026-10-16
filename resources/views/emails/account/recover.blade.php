<x-mail::message>
    <table width="100%">
        <tr>
            <th align="center">
                <img src="{{ asset('assets/images/xgp-logo-black.png') }}" alt="XG Proyect Logo" width="250px"/>
            </th>
        </tr>
        <tr>
            <td>&nbsp;</td>
        </tr>
        <tr>
            <td>{!! __('emails/recover.re_mail_text_part1') !!}</td>
        </tr>
        <tr>
            <td>{{ __('emails/recover.re_mail_text_part2', ['minutes' => $minutes]) }}</td>
        </tr>
        <tr>
            <td>
                <x-mail::button :url="$resetUrl">
                    {{ __('emails/recover.re_mail_text_part3') }}
                </x-mail::button>
            </td>
        </tr>
        <tr>
            <td>&nbsp;</td>
        </tr>
        <tr>
            <td>{{ __('emails/recover.re_mail_text_part4') }}</td>
        </tr>
        <tr>
            <td>&nbsp;</td>
        </tr>
        <tr>
            <td>{!! __('emails/recover.re_mail_text_part5', ['game' => $gameName]) !!}</td>
        </tr>
    </table>
</x-mail::message>