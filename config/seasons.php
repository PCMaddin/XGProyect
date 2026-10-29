<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Season rewards
|--------------------------------------------------------------------------
|
| The best ten of each ranking get dark matter when a season ends, to use
| in a later Pay2Win universe. A universe keeps the plan it was created
| with; `xgp:instance:update --rewards --shares` changes it.
|
| rewards: dark matter per ranking (total, buildings, technology, ships,
|          defenses, military), as name=amount pairs separated by commas.
| shares:  percent of a ranking's dark matter for places 1, 2, 3 …
|          (at most ten, together at most 100).
|
*/

return [
    'rewards' => env('XGP_SEASON_REWARDS', 'total=100000,buildings=20000,technology=20000,ships=20000,defenses=20000,military=20000'),
    'shares' => env('XGP_SEASON_SHARES', '30,20,15,10,7,6,4,3,3,2'),
];
