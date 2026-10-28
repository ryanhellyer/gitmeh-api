<?php

declare(strict_types=1);

namespace App\Service;

use Predis\Client;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Per-IP, per-endpoint hourly rate limiter backed by Redis hashes keyed by clock
 * hour and bucket:
 *   api:hits:<bucket>:Y-m-d-H  ->  { ip: count, ... }
 *
 * Each endpoint family (see Limit) gets its own bucket, so exhausting the
 * translations quota never blocks gitmeh, and vice versa. Every bucket has its
 * own hourly ceiling.
 *
 * A fixed clock-hour bucket (not a sliding window): worst case a user straddling
 * two hours gets 2× the limit in one 60-minute span, which is acceptable for a
 * free hosted service.
 */
final class ApiHourlyLimiter
{
    private readonly string $timezone;

    /** @var array<string, int> bucket name => hourly limit */
    private readonly array $limits;

    /**
     * @param array<string, int|string> $limits bucket name => hourly limit, from config
     */
    public function __construct(
        private readonly Client $redis,
        #[Autowire('%gitmeh.timezone%')]
        ?string $timezone = null,
        #[Autowire('%gitmeh.hourly_limits%')]
        array $limits = [],
    ) {
        $this->timezone = $timezone ?? 'UTC';

        $resolved = [];
        foreach ($limits as $bucket => $limit) {
            $resolved[$bucket] = max(1, (int) $limit);
        }
        $this->limits = $resolved;
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    /**
     * Hourly ceiling for one bucket.
     */
    public function hourlyLimit(Limit $limit): int
    {
        return $this->limits[$limit->bucket()] ?? 50;
    }

    /**
     * Redis hash key for the current clock hour and bucket:
     * api:hits:<bucket>:Y-m-d-H
     */
    public function currentHourKey(Limit $limit): string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone($this->timezone));

        return sprintf('api:hits:%s:%s', $limit->bucket(), $now->format('Y-m-d-H'));
    }

    public function currentUsage(Limit $limit, string $ip): int
    {
        $raw = $this->redis->hget($this->currentHourKey($limit), $ip);

        return $raw === null ? 0 : (int) $raw;
    }

    public function remaining(Limit $limit, string $ip): int
    {
        return max(0, $this->hourlyLimit($limit) - $this->currentUsage($limit, $ip));
    }

    /**
     * Try to consume one request for this IP in this bucket. Returns false if the
     * hourly limit is exceeded (counter rolled back so rejected requests don't count).
     */
    public function attempt(Limit $limit, string $ip): bool
    {
        $key = $this->currentHourKey($limit);
        $count = (int) $this->redis->hincrby($key, $ip, 1);

        if ($count > $this->hourlyLimit($limit)) {
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
     * Usage for one IP in one bucket.
     *
     * @return array{used: int, remaining: int, limit: int, timezone: string, label: string}
     */
    public function statusForIp(Limit $limit, string $ip): array
    {
        $limitValue = $this->hourlyLimit($limit);

        return [
            'used' => $this->currentUsage($limit, $ip),
            'remaining' => $this->remaining($limit, $ip),
            'limit' => $limitValue,
            'timezone' => $this->timezone,
            'label' => $limit->label(),
        ];
    }

    /**
     * Usage for one IP across every bucket, for the status page.
     *
     * @return list<array{used: int, remaining: int, limit: int, timezone: string, label: string}>
     */
    public function statusForAllLimits(string $ip): array
    {
        return array_map(
            fn (Limit $limit): array => $this->statusForIp($limit, $ip),
            Limit::cases(),
        );
    }

    /**
     * Best-effort archival of the previous hour's Redis hit hashes, summed across
     * every bucket.
     *
     * @return int Total number of requests recorded for the previous hour (0 if no key existed)
     */
    public function flushCompletedHours(): int
    {
        $lastHour = (new \DateTimeImmutable('now', new \DateTimeZone($this->timezone)))->modify('-1 hour');
        $suffix = $lastHour->format('Y-m-d-H');

        $total = 0;
        foreach ($this->redis->keys('api:hits:*:' . $suffix) as $key) {
            $rows = $this->redis->hgetall($key);
            if ($rows === [] || $rows === false) {
                $this->redis->del($key);

                continue;
            }

            foreach ($rows as $count) {
                $total += (int) $count;
            }

            $this->redis->del($key);
        }

        return $total;
    }

    public function lastHourString(): string
    {
        $lastHour = (new \DateTimeImmutable('now', new \DateTimeZone($this->timezone)))->modify('-1 hour');

        return $lastHour->format('Y-m-d H:00');
    }
}
