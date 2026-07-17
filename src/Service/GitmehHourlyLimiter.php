<?php

declare(strict_types=1);

namespace App\Service;

use Predis\Client;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Per-IP hourly rate limiter backed by a Redis hash keyed by clock hour:
 *   gitmeh:hits:Y-m-d-H  ->  { ip: count, ... }
 *
 * A fixed clock-hour bucket (not a sliding window): worst case a user straddling
 * two hours gets 2× the limit in one 60-minute span, which is acceptable for a
 * free hosted service.
 */
final class GitmehHourlyLimiter
{
    private readonly string $timezone;
    private readonly int $hourlyLimit;

    public function __construct(
        private readonly Client $redis,
        #[Autowire('%gitmeh.timezone%')]
        ?string $timezone = null,
        #[Autowire('%gitmeh.hourly_limit%')]
        int $hourlyLimit = 50,
    ) {
        $this->timezone = $timezone ?? 'UTC';
        $this->hourlyLimit = max(1, $hourlyLimit);
    }

    public function hourlyLimit(): int
    {
        return $this->hourlyLimit;
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    /**
     * Redis hash key for the current clock hour: gitmeh:hits:Y-m-d-H
     */
    public function currentHourKey(): string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone($this->timezone));

        return 'gitmeh:hits:' . $now->format('Y-m-d-H');
    }

    public function currentUsage(string $ip): int
    {
        $raw = $this->redis->hget($this->currentHourKey(), $ip);

        return $raw === null ? 0 : (int) $raw;
    }

    public function remaining(string $ip): int
    {
        return max(0, $this->hourlyLimit - $this->currentUsage($ip));
    }

    /**
     * Try to consume one request for this IP. Returns false if the hourly limit is
     * exceeded (counter rolled back so rejected requests don't count).
     */
    public function attempt(string $ip): bool
    {
        $key = $this->currentHourKey();
        $count = (int) $this->redis->hincrby($key, $ip, 1);

        if ($count > $this->hourlyLimit) {
            $this->redis->hincrby($key, $ip, -1);

            return false;
        }

        return true;
    }

    /**
     * Seconds until the end of the current clock hour in the rate-limit timezone.
     */
    public function retryAfterSeconds(): int
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone($this->timezone));
        $end = $now->setTime((int) $now->format('H'), 59, 59);

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
            'limit' => $this->hourlyLimit,
            'timezone' => $this->timezone,
        ];
    }

    /**
     * Best-effort archival of the previous hour's Redis hit hash.
     *
     * @return int Total number of requests recorded for the previous hour (0 if the key didn't exist)
     */
    public function flushCompletedHours(): int
    {
        $lastHour = (new \DateTimeImmutable('now', new \DateTimeZone($this->timezone)))->modify('-1 hour');
        $key = 'gitmeh:hits:' . $lastHour->format('Y-m-d-H');

        if (!$this->redis->exists($key)) {
            return 0;
        }

        $rows = $this->redis->hgetall($key);
        if ($rows === [] || $rows === false) {
            $this->redis->del($key);

            return 0;
        }

        $total = 0;
        foreach ($rows as $count) {
            $total += (int) $count;
        }

        $this->redis->del($key);

        return $total;
    }

    public function lastHourString(): string
    {
        $lastHour = (new \DateTimeImmutable('now', new \DateTimeZone($this->timezone)))->modify('-1 hour');

        return $lastHour->format('Y-m-d H:00');
    }
}
