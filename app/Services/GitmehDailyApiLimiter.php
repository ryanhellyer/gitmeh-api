<?php

namespace App\Services;

use App\Models\ApiIpDailyHit;
use Carbon\CarbonImmutable;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

class GitmehDailyApiLimiter
{
    protected function redis(): Connection
    {
        return Redis::connection();
    }

    public function timezone(): string
    {
        return config('gitmeh.timezone') ?: (string) config('app.timezone');
    }

    public function dailyLimit(): int
    {
        return max(1, (int) config('gitmeh.daily_limit', 1000));
    }

    /**
     * Redis hash key for a calendar day: gitmeh:hits:Y-m-d
     */
    public function dayHashKey(CarbonImmutable $day): string
    {
        return 'gitmeh:hits:'.$day->format('Y-m-d');
    }

    public function todayKey(): string
    {
        return $this->dayHashKey(CarbonImmutable::now($this->timezone()));
    }

    public function currentUsage(string $ip): int
    {
        $key = $this->todayKey();
        $raw = $this->redis()->hget($key, $ip);

        return $raw === false ? 0 : (int) $raw;
    }

    public function remaining(string $ip): int
    {
        return max(0, $this->dailyLimit() - $this->currentUsage($ip));
    }

    /**
     * Try to consume one request for this IP. Returns false if the daily limit is exceeded (counter unchanged).
     */
    public function attempt(string $ip): bool
    {
        $limit = $this->dailyLimit();
        $key = $this->todayKey();
        $count = (int) $this->redis()->hincrby($key, $ip, 1);
        if ($count > $limit) {
            $this->redis()->hincrby($key, $ip, -1);

            return false;
        }

        return true;
    }

    /**
     * Seconds until end of day in the rate-limit timezone.
     */
    public function retryAfterSeconds(): int
    {
        $tz = $this->timezone();
        $now = CarbonImmutable::now($tz);
        $end = $now->endOfDay();

        return max(1, (int) $now->diffInSeconds($end));
    }

    /**
     * Flush completed days from Redis into the database (dates strictly before today in rate-limit TZ).
     *
     * @return int Number of Redis day keys deleted
     */
    public function flushCompletedDaysToDatabase(): int
    {
        $tz = $this->timezone();
        $todayStart = CarbonImmutable::now($tz)->startOfDay();
        $deleted = 0;

        for ($i = 1; $i <= 400; $i++) {
            $day = CarbonImmutable::now($tz)->subDays($i)->startOfDay();
            if ($day->greaterThanOrEqualTo($todayStart)) {
                continue;
            }

            $key = $this->dayHashKey($day);
            if (! $this->redis()->exists($key)) {
                continue;
            }

            $rows = $this->redis()->hgetall($key);
            if ($rows === [] || $rows === false) {
                $this->redis()->del($key);
                $deleted++;

                continue;
            }

            $dayString = $day->format('Y-m-d');
            foreach ($rows as $ip => $count) {
                ApiIpDailyHit::query()->updateOrCreate(
                    ['ip' => $ip, 'day' => $dayString],
                    ['hit_count' => (int) $count]
                );
            }

            $this->redis()->del($key);
            $deleted++;
        }

        return $deleted;
    }
}
