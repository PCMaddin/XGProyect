<table width="665px">
    <tr>
        <td class="c">{{ __('game/trader.tr_scrap_merchant') }}</td>
    </tr>
    <tr>
        <th>
            <p><em>„{{ $phrase }}“</em></p>
            <h3>{{ $offer }}</h3>
            @if ($canBargain)
            <form name="scrap-bargain" method="POST" action="game.php?page=traderScrap" role="form">
                @csrf
                {{ $bargainPrice }}<br>
                <input type="submit" name="bargain" value="{{ __('game/trader.tr_scrap_bargain_button') }}">
            </form>
            @endif
        </th>
    </tr>
    <tr>
        <td>
            <form name="scrap-units" method="POST" action="game.php?page=traderScrap" role="form">
                @csrf
                <table width="100%">
                    @if ($units === [])
                    <tr>
                        <th>{{ __('game/trader.tr_scrap_none') }}</th>
                    </tr>
                    @else
                    <tr>
                        <td class="c" colspan="2">{{ __('game/trader.tr_scrap_unit') }}</td>
                        <td class="c">{{ __('game/trader.tr_scrap_per_unit') }}</td>
                        <td class="c">{{ __('game/trader.tr_scrap_amount') }}</td>
                    </tr>
                    @foreach ($units as $unit)
                    <tr>
                        <th width="50">
                            <img border="0" src="{{ asset('assets/upload/skins/xgproyect/elements/' . $unit['id'] . '.gif') }}" width="40" height="40" alt="{{ $unit['name'] }}"/>
                        </th>
                        <th style="text-align:left">
                            {{ $unit['name'] }}<br>
                            ({{ $unit['availableText'] }} {{ __('game/trader.tr_available') }})
                        </th>
                        <th>
                            @foreach ($unit['payment'] as $resource => $amount)
                            @if ($amount > 0)
                            {{ __('game/global.' . $resource) }}: {{ number_format($amount, 0, ',', '.') }}<br>
                            @endif
                            @endforeach
                        </th>
                        <th>
                            <input type="number" min="0" max="{{ $unit['available'] }}" step="1" name="scrap_{{ $unit['id'] }}" value="0" class="scrap-amount" style="width: 90px"
                                data-metal="{{ $unit['payment']['metal'] }}" data-crystal="{{ $unit['payment']['crystal'] }}" data-deuterium="{{ $unit['payment']['deuterium'] }}">
                            <br><a href="#" class="scrap-all" data-max="{{ $unit['available'] }}">{{ __('game/trader.tr_scrap_all') }}</a>
                        </th>
                    </tr>
                    @endforeach
                    <tr>
                        <th colspan="2" style="text-align:right">{{ __('game/trader.tr_you_get') }}:</th>
                        <th colspan="2" style="text-align:left">
                            {{ __('game/global.metal') }}: <span id="scrap-metal">0</span><br>
                            {{ __('game/global.crystal') }}: <span id="scrap-crystal">0</span><br>
                            {{ __('game/global.deuterium') }}: <span id="scrap-deuterium">0</span>
                        </th>
                    </tr>
                    <tr>
                        <th colspan="2" style="text-align:right">{{ __('game/trader.tr_free_storage') }}:</th>
                        <th colspan="2" style="text-align:left">
                            @foreach ($freeStorage as $resource => $free)
                            {{ __('game/global.' . $resource) }}: {{ $free }}<br>
                            @endforeach
                        </th>
                    </tr>
                    <tr>
                        <th colspan="4"><input type="submit" name="scrap" value="{{ __('game/trader.tr_scrap_button') }}"></th>
                    </tr>
                    @endif
                </table>
            </form>
            @if ($units !== [])
            <script type="text/javascript">
                (function () {
                    var inputs = document.querySelectorAll('.scrap-amount');

                    function format(value) {
                        return String(value).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                    }

                    function update() {
                        ['metal', 'crystal', 'deuterium'].forEach(function (resource) {
                            var total = 0;
                            Array.prototype.forEach.call(inputs, function (input) {
                                var amount = Math.min(parseInt(input.value, 10) || 0, parseInt(input.max, 10));
                                total += amount > 0 ? amount * parseInt(input.getAttribute('data-' + resource), 10) : 0;
                            });
                            document.getElementById('scrap-' + resource).textContent = format(total);
                        });
                    }

                    Array.prototype.forEach.call(inputs, function (input) {
                        input.addEventListener('input', update);
                    });
                    Array.prototype.forEach.call(document.querySelectorAll('.scrap-all'), function (link) {
                        link.addEventListener('click', function (event) {
                            event.preventDefault();
                            link.parentNode.querySelector('.scrap-amount').value = link.getAttribute('data-max');
                            update();
                        });
                    });
                })();
            </script>
            @endif
        </td>
    </tr>
</table>
