@extends('master.game')

@section('content')
<table role="presentation" width="600">
    <tr>
        <td role="heading" aria-level="2" colspan="4" class="c">{{ __('game/darkmatter.dm_title') }}</td>
    </tr>
    <tr>
        <td class="l" style="width:120px;">
            <img src="{{ asset('assets/upload/skins/xgproyect/premium/DMaterie.jpg') }}" width="120" height="120" alt=""/>
        </td>
        <td class="l" colspan="3">
            {{ __('game/darkmatter.dm_intro') }}
        </td>
    </tr>
    <tr>
        <td class="c"></td>
        <td class="c">{{ __('game/global.darkmatter') }}</td>
        <td class="c">{{ __('game/darkmatter.dm_price') }}</td>
        <td class="c"></td>
    </tr>
    @foreach ($packages as $package)
    <tr>
        <th style="text-align:center;">
            <img src="{{ asset('assets/upload/skins/xgproyect/premium/dm_klein_1.jpg') }}" width="32" height="32" style="vertical-align:middle;" alt=""/>
        </th>
        <th>
            <strong style="color:skyblue;">{{ $package['dark_matter'] }}</strong>
        </th>
        <th>
            <strong style="color:lime;">{{ $package['price'] }}</strong>
        </th>
        <th>
            <form action="game.php?page=darkmatter" method="post">
                @csrf
                <input type="hidden" name="package" value="{{ $package['id'] }}"/>
                <input type="submit" value="{{ __('game/darkmatter.dm_buy') }}"/>
            </form>
        </th>
    </tr>
    @endforeach
    <tr>
        <th colspan="4">
            <a href="game.php?page=premium">{{ __('game/darkmatter.dm_back') }}</a>
        </th>
    </tr>
</table>
@endsection
