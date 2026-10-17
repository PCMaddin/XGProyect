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
                        @if ($universe['players'] !== null)
                            · {{ __('home/lobby.lb_players', ['count' => $universe['players']]) }}
                        @endif
                    </div>
                    <div class="meta">{{ __('home/lobby.lb_mode_' . $universe['mode'] . '_explained') }}</div>
                </div>
                @if ($universe['open'])
                    <form method="POST" action="{{ route('lobby.choose', ['slug' => $universe['slug']]) }}">
                        @csrf
                        <button type="submit">{{ __('home/lobby.lb_play') }}</button>
                    </form>
                @else
                    <span class="ended">{{ __('home/lobby.lb_closed') }}</span>
                @endif
            </li>
        @endforeach
    </ul>
</main>
</body>
</html>
