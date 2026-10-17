<x-mail::message>
    <table width="100%">
        <tr>
            <th align="center">
                <img src="{{ asset('assets/images/xgp-logo-black.png') }}" alt="XG Proyect Logo" width="250px"/>
            </th>
        </tr>
        <tr>
            <td>{!! $content !!}</td>
        </tr>
        <tr>
            <td>&nbsp;</td>
        </tr>
        <tr>
            <td>
                <x-mail::button :url="$gameUrl">
                    {{ $gameName }}
                </x-mail::button>
            </td>
        </tr>
    </table>
</x-mail::message>