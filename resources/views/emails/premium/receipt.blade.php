<x-mail::message>
# {{ __('emails/receipt.rc_title') }}

{{ __('emails/receipt.rc_thanks', ['game' => $gameName]) }}
@if ($test)

**{{ __('emails/receipt.rc_test') }}**
@endif

<x-mail::table>
| | |
|:--|--:|
| {{ __('emails/receipt.rc_order') }} | {{ $orderId }} |
| {{ __('emails/receipt.rc_date') }} | {{ date('Y-m-d H:i T', $paidAt) }} |
| {{ __('emails/receipt.rc_item') }} | {{ $darkMatterText }} |
| {{ __('emails/receipt.rc_method') }} | {{ __('emails/receipt.rc_method_' . $method) }} |
| **{{ __('emails/receipt.rc_price') }}** | **{{ $price }}** |
@if ($tax !== null)
| {{ __('emails/receipt.rc_tax') }} | {{ $tax }} |
@endif
</x-mail::table>

{{ __('emails/receipt.rc_waiver', ['date' => date('Y-m-d H:i T', $waivedAt)]) }}

@foreach ($links as $page => $url)
[{{ __('home/legal.lg_' . $page) }}]({{ $url }})@if (!$loop->last) · @endif
@endforeach

<x-mail::button :url="$gameUrl">
{{ __('emails/receipt.rc_to_game') }}
</x-mail::button>
</x-mail::message>
