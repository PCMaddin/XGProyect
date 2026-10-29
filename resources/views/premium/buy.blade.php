{{-- the button to the server's dark matter shop, only when there is one --}}
@if ($url !== '')
<a id='darkmatter2' href='{{ $url }}' style='cursor:pointer; text-align:center;width:100px;height:60px;'><br>
    <div id='darkmatter2'><strong>{{ __('game/officier.of_get_darkmatter') }}</strong></div>
</a>
@endif
