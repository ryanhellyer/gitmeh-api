<?php

declare(strict_types=1);

return [

    'daily_limit' => (int) env('API_DAILY_LIMIT', 1000),

    'timezone' => env('API_RATE_LIMIT_TIMEZONE'),

];
