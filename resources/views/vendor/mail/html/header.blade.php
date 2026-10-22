{{-- Laravel's header without its logo from laravel.com, which it showed when the app name was "Laravel" --}}
@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{!! $slot !!}
</a>
</td>
</tr>
