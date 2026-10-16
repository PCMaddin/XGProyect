<table width="665px">
    <tr>
        <td class="c">{{ __('game/trader.tr_resource_market') }}</td>
    </tr>
    <tr>
        <td>
            <form name="refill-resources" method="POST" action="" role="form">
                @csrf
                <table width="100%">
                    <tr>
                        <td class="c" colspan="2">{{ __('game/trader.tr_merchant1_tab_title') }}</td>
                    </tr>
                    <tr>
                        <th colspan="2">
                            <h2>{{ __('game/trader.tr_merchant1_title') }}</h2>
                            <p>{{ __('game/trader.tr_merchant1_explanation') }}</p>
                        </th>
                    </tr>
                    <tr>
                        <td class="c" colspan="2">
                            <h3>{{ __('game/trader.tr_merchant1_info') }}</h3>
                        </td>
                    </tr>
                    @foreach ($resourcesList as $resource)
                    <tr>
                        <th>
                            <img border="0" src="{{ asset('assets/upload/skins/xgproyect/resources/' . $resource['resource'] . '.gif') }}" width="42" height="22" alt=""/>
                            <br>
                            {{ $resource['resourceName'] }}
                        </th>
                        <td>
                            <table width="100%">
                                <tr>
                                    <th colspan="3">
                                        {{ __('game/trader.tr_storage_capacity') }}:
                                        {{ $resource['currentResource'] }} / {{ $resource['maxResource'] }}
                                    </th>
                                </tr>
                                <tr>
                                    @foreach ($resource['refillOptions'] as $option)
                                    <th>
                                        {{ $option['label'] }}:<br>
                                        <span style="font-size: 3em">{{ $option['percentage'] }}%</span><br>
                                        {{ __('game/trader.tr_requires') }}:<br>
                                        {!! $option['price'] !!}<br>
                                        {!! $option['button'] !!}
                                    </th>
                                    @endforeach
                                </tr>
                            </table>
                        </td>
                    </tr>
                    @endforeach
                </table>
            </form>
            <form name="trade-resources" method="POST" action="game.php?page=traderResources" role="form">
                @csrf
                <table width="100%">
                    <tr>
                        <td class="c" colspan="{{ $merchant['offer'] ? 4 : 2 }}">{{ __('game/trader.tr_merchant2_tab_title') }}</td>
                    </tr>
                    <tr>
                        <th colspan="{{ $merchant['offer'] ? 4 : 2 }}">
                            <h2>{{ __('game/trader.tr_merchant2_title') }}</h2>
                        </th>
                    </tr>
                    @if ($merchant['offer'])
                    <tr>
                        <th colspan="4">{{ $merchant['offerText'] }}</th>
                    </tr>
                    <tr>
                        <td class="c">{{ __('game/trader.tr_resource') }}</td>
                        <td class="c">{{ __('game/trader.tr_exchange_rate') }}</td>
                        <td class="c">{{ __('game/trader.tr_free_storage') }}</td>
                        <td class="c">{{ __('game/trader.tr_you_get') }}</td>
                    </tr>
                    @foreach ($merchant['buy'] as $item)
                    <tr>
                        <th>
                            <img border="0" src="{{ asset('assets/upload/skins/xgproyect/resources/' . $item['resource'] . '.gif') }}" width="42" height="22" alt=""/><br>
                            {{ $item['name'] }}
                        </th>
                        <th>{{ $item['rateText'] }}</th>
                        <th>{{ $item['free'] }}</th>
                        <th><input type="number" min="0" step="1" name="get_{{ $item['resource'] }}" value="0" data-rate="{{ $item['rate'] }}" class="merchant-amount" style="width: 110px"></th>
                    </tr>
                    @endforeach
                    <tr>
                        <th colspan="3" style="text-align:right">{{ __('game/trader.tr_you_pay') }} ({{ $merchant['sellName'] }}, {{ __('game/trader.tr_available') }}: {{ $merchant['available'] }}):</th>
                        <th><span id="merchant-cost">0</span></th>
                    </tr>
                    <tr>
                        <th colspan="4"><input type="submit" name="exchange" value="{{ __('game/trader.tr_exchange_button') }}"></th>
                    </tr>
                    @else
                    <tr>
                        <th style="text-align:left">
                            {{ __('game/trader.tr_step1') }}
                        </th>
                        <th style="text-align:left">
                            {{ __('game/trader.tr_step2') }}
                        </th>
                    </tr>
                    <tr>
                        <th width="50%">
                            <table width="100%">
                                <tr>
                                    @foreach (['metal', 'crystal', 'deuterium'] as $resource)
                                    <th>
                                        <img border="0" src="{{ asset('assets/upload/skins/xgproyect/resources/' . $resource . '.gif') }}" width="42" height="22" alt=""/>
                                    </th>
                                    @endforeach
                                </tr>
                                <tr>
                                    @foreach (['metal', 'crystal', 'deuterium'] as $resource)
                                    <th>
                                        <label>{{ __('game/global.' . $resource) }}
                                        <input type="radio" name="sell" value="{{ $resource }}"{{ $loop->first ? ' checked' : '' }}></label>
                                    </th>
                                    @endforeach
                                </tr>
                            </table>
                        </th>
                        <th width="50%">
                            {{ sprintf(__('game/trader.tr_price'), $exchangePrice) }}<br>
                            <input type="submit" name="call" value="{{ __('game/trader.tr_call_button') }}">
                        </th>
                    </tr>
                    @endif
                </table>
            </form>
            @if ($merchant['offer'])
            <script type="text/javascript">
                (function () {
                    var inputs = document.querySelectorAll('.merchant-amount');
                    var cost = document.getElementById('merchant-cost');

                    function update() {
                        var total = 0;
                        Array.prototype.forEach.call(inputs, function (input) {
                            var amount = parseInt(input.value, 10) || 0;
                            var rate = parseFloat(input.getAttribute('data-rate'));
                            total += amount > 0 && rate > 0 ? amount / rate : 0;
                        });
                        cost.textContent = String(Math.ceil(total)).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                    }

                    Array.prototype.forEach.call(inputs, function (input) {
                        input.addEventListener('input', update);
                    });
                })();
            </script>
            @endif
        </td>
    </tr>
</table>