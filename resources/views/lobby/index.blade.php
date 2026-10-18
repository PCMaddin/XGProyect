<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('home/lobby.lb_title') }}</title>
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
    <style>
        body { margin: 0; background: #0b0f1a; color: #d8dde8; font: 15px/1.5 Verdana, Arial, sans-serif; }
        main { max-width: 760px; margin: 0 auto; padding: 32px 16px; }
        h1 { font-size: 26px; margin: 0 0 4px; color: #fff; }
        p.lead { margin: 0 0 24px; color: #9aa4b8; }
        ul { list-style: none; padding: 0; margin: 0; display: grid; gap: 12px; }
        li { background: #151b2b; border: 1px solid #263049; border-radius: 8px; padding: 16px; display: flex; gap: 16px; align-items: center; justify-content: space-between; flex-wrap: wrap; }
        li.closed { opacity: .55; }
        li.chosen { border-color: #4f7cff; }
        .name { font-size: 18px; color: #fff; font-weight: bold; }
        .meta { color: #9aa4b8; font-size: 13px; }
        .mode { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 12px; margin-left: 8px; }
        .mode.pay2win { background: #3d2a12; color: #ffbf66; }
        .mode.play2win { background: #12301f; color: #6fe3a0; }
        button { background: #4f7cff; color: #fff; border: 0; border-radius: 6px; padding: 8px 18px; font-size: 15px; cursor: pointer; }
        button:hover { background: #3d68e6; }
        .ended { color: #9aa4b8; }
        details.fame { flex-basis: 100%; margin-top: 4px; }
        details.fame summary { cursor: pointer; color: #ffd27a; font-size: 14px; }
        .fame-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px 24px; margin-top: 10px; }
        .fame-grid h3 { font-size: 13px; margin: 0 0 4px; color: #fff; }
        .fame-grid ol { margin: 0; padding-left: 22px; font-size: 13px; color: #c3cad8; }
        .fame-grid .pts { color: #9aa4b8; font-variant-numeric: tabular-nums; }
        .fame-grid .prize, .rewards { color: #c7b8ff; }
    </style>
</head>
<body>
<main>
    <h1>{{ __('home/lobby.lb_title') }}</h1>
    <p class="lead">{{ __('home/lobby.lb_lead') }}</p>
    <ul>
        @foreach ($universes as $universe)
            <li class="{{ $universe['open'] ? '' : 'closed' }} {{ $chosen === $universe['slug'] ? 'chosen' : '' }}">
                <div>
                    <span class="name">{{ $universe['name'] }}</span>
                    <span class="mode {{ $universe['mode'] }}">{{ __('home/lobby.lb_mode_' . $universe['mode']) }}</span>
                    <div class="meta">
                        {{ __('home/lobby.lb_opened', ['date' => date('d.m.Y', $universe['opened_at'])]) }}
                        @if ($universe['open'] && $universe['ends_at'] !== null)
                            · <span class="ends">{{ __('home/lobby.lb_ends', ['date' => date('d.m.Y H:i', $universe['ends_at'])]) }}</span>
                        @endif
                        @if ($universe['players'] !== null)
                            · {{ __('home/lobby.lb_players', ['count' => $universe['players']]) }}
                        @endif
                    </div>
                    <div class="meta">{{ __('home/lobby.lb_mode_' . $universe['mode'] . '_explained') }}</div>
                    @if ($universe['rewards'] !== null && array_sum($universe['rewards']) > 0)
                        <div class="meta rewards">
                            {{ __('home/lobby.lb_rewards', ['amount' => number_format(array_sum($universe['rewards']), 0, ',', '.')]) }}
                            ({{ implode(', ', array_map(static fn (string $category, int $amount): string => __('home/lobby.lb_category_' . $category) . ' ' . number_format($amount, 0, ',', '.'), array_keys(array_filter($universe['rewards'])), array_filter($universe['rewards']))) }})
                        </div>
                    @endif
                    @if ($universe['payments'] !== [])
                        <div class="meta payments">{{ __('home/lobby.lb_payments', ['methods' => implode(', ', array_map(static fn (string $method): string => __('home/lobby.lb_payment_' . $method), $universe['payments']))]) }}</div>
                    @endif
                </div>
                @if ($universe['open'])
                    <form method="POST" action="{{ route('lobby.choose', ['slug' => $universe['slug']]) }}">
                        @csrf
                        <button type="submit">{{ __('home/lobby.lb_play') }}</button>
                    </form>
                @else
                    <span class="ended">{{ __('home/lobby.lb_closed') }}</span>
                @endif
                @if ($universe['hall_of_fame'] !== [])
                    <details class="fame">
                        <summary>{{ __('home/lobby.lb_hall_of_fame') }}</summary>
                        <div class="fame-grid">
                            @foreach ($universe['hall_of_fame'] as $category => $places)
                                <div>
                                    <h3>{{ __('home/lobby.lb_category_' . $category) }}</h3>
                                    <ol>
                                        @foreach ($places as $place)
                                            <li>{{ $place['name'] }}@if ($place['alliance_tag'] !== null) [{{ $place['alliance_tag'] }}]@endif <span class="pts">{{ number_format($place['points'], 0, ',', '.') }}</span>@if ($place['reward'] > 0) <span class="prize">+{{ number_format($place['reward'], 0, ',', '.') }} {{ __('home/lobby.lb_dm') }}</span>@endif</li>
                                        @endforeach
                                    </ol>
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endif
            </li>
        @endforeach
    </ul>
</main>
</body>
</html>
