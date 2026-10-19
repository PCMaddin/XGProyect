<table role="status" width="600" id="verify-email" style="margin-bottom:8px;">
    <tr>
        <th style="text-align:left;color:orange;">
            {{ __('account/verify.vf_banner', ['email' => $email, 'date' => date('Y-m-d H:i', $deadline)]) }}
            @if (session('verify_email_status'))
                <br><span style="color:lime;">{{ session('verify_email_status') }}</span>
            @endif
        </th>
        <th style="width:150px;">
            <form method="POST" action="{{ route('account.verify.resend') }}" style="margin:0;">
                @csrf
                <input type="submit" value="{{ __('account/verify.vf_resend') }}">
            </form>
        </th>
    </tr>
</table>
