<?php

declare(strict_types=1);

namespace App\Service;

use Predis\Client;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class GitmehDailyApiLimiter
{
    private readonly string $timezone;
    private readonly int $dailyLimit;

    public function __construct(
        private readonly Client $redis,
        #[Autowire('%gitmeh.timezone%')]
        ?string $timezone = null,
        #[Autowire('%gitmeh.daily_limit%')]
        int $dailyLimit = 1000,
    ) {
        $this->timezone = $timezone ?? 'UTC';
        $this->dailyLimit = max(1, $dailyLimit);
    }

    public function dailyLimit(): int
    {
        return $this->dailyLimit;
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    public function todayKey(): string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone($this->timezone));

        return 'gitmeh:hits:' . $now->format('Y-m-d');
    }

    public function currentUsage(string $ip): int
    {
        $raw = $this->redis->hget($this->todayKey(), $ip);

        return $raw === null ? 0 : (int) $raw;
    }

    public function remaining(string $ip): int
    {
        return max(0, $this->dailyLimit - $this->currentUsage($ip));
    }

    /**
     * Try to consume one request for this IP. Returns false if the daily limit is
     * exceeded (counter rolled back so rejected requests don't count).
     */
    public function attempt(string $ip): bool
    {
        $key = $this->todayKey();
        $count = (int) $this->redis->hincrby($key, $ip, 1);

        if ($count > $this->dailyLimit) {
            $this->redis->hincrby($key, $ip, -1);

            return false;
        }

        return true;
    }

    /**
     * Seconds until end of day in the rate-limit timezone.
     */
    public function retryAfterSeconds(): int
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone($this->timezone));
        $end = $now->setTime(23, 59, 59);

        return max(1, $end->getTimestamp() - $now->getTimestamp());
    }

    /**
     * @return array{used: int, remaining: int, limit: int, timezone: string}
     */
    public function statusForIp(string $ip): array
    {
        return [
            'used' => $this->currentUsage($ip),
            'remaining' => $this->remaining($ip),
            'limit' => $this->dailyLimit,
            'timezone' => $this->timezone,
        ];
    }

    /**
     * Best-effort archival of yesterday's Redis hit hash.
     *
     * @return int 1 if yesterday's Redis day key existed and was removed, 0 otherwise
     */
    public function flushCompletedDays(): int
    {
        $yesterday = new \DateTimeImmutable('yesterday', new \DateTimeZone($this->timezone));
        $key = 'gitmeh:hits:' . $yesterday->format('Y-m-d');

        if (!$this->redis->exists($key)) {
            return 0;
        }

        $rows = $this->redis->hgetall($key);
        if ($rows === [] || $rows === false) {
            $this->redis->del($key);

            return 1;
        }

        // The Laravel app persists these to a SQLite table. Persistence to PostgreSQL
        // is out of scope for the initial build; the counts are discarded after removal.
        // Add a Doctrine entity + migration here if historical archival is needed.
        $this->redis->del($key);

        return 1;
    }
}
