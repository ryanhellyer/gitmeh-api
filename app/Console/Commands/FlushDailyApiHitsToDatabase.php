<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\GitmehDailyApiLimiter;
use Illuminate\Console\Command;

class FlushDailyApiHitsToDatabase extends Command
{
    protected $signature = 'api:flush-daily-hits';

    protected $description = 'Persist yesterday\'s Redis gitmeh hit hash to the database and remove that Redis key';

    public function handle(GitmehDailyApiLimiter $limiter): int
    {
        $deleted = $limiter->flushCompletedDaysToDatabase();
        $this->info('Redis day keys processed (deleted after persist): '.$deleted);

        return self::SUCCESS;
    }
}
