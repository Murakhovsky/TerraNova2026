<?php

declare(strict_types=1);

namespace App\Security;

use App\Infrastructure\Concurrency\MySqlAdvisoryLock;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Cache\CacheItemPoolInterface;

final readonly class SecurityRateLimiter
{
    public function __construct(
        private CacheItemPoolInterface $cache,
        private MySqlAdvisoryLock $locks,
    ) {
    }

    public function consume(
        string $bucket,
        string $subject,
        int $limit,
        int $windowSeconds,
    ): RateLimitDecision {
        $bucket = trim($bucket);
        $subject = trim($subject);

        if ($bucket === '' || $subject === '' || $limit <= 0 || $windowSeconds <= 0) {
            throw new InvalidArgumentException('Rate limit bucket, subject, limit and window must be valid.');
        }

        $key = hash('sha256', $bucket . '|' . $subject);

        return $this->locks->synchronized('security-rate-limit:' . $key, function () use ($key, $limit, $windowSeconds): RateLimitDecision {
            $now = time();
            $cacheKey = 'cos_security_rate_' . $key;
            $item = $this->cache->getItem($cacheKey);
            $state = $item->isHit() ? $item->get() : null;

            if (!is_array($state) || (int) ($state['reset_at'] ?? 0) <= $now) {
                $state = [
                    'count' => 0,
                    'reset_at' => $now + $windowSeconds,
                ];
            }

            $state['count'] = (int) $state['count'] + 1;
            $resetAt = (int) $state['reset_at'];

            $item->set($state);
            $item->expiresAt((new DateTimeImmutable())->setTimestamp($resetAt));
            $this->cache->save($item);

            $allowed = (int) $state['count'] <= $limit;

            return new RateLimitDecision(
                allowed: $allowed,
                limit: $limit,
                remaining: max(0, $limit - (int) $state['count']),
                retryAfterSeconds: max(1, $resetAt - $now),
            );
        });
    }
}
