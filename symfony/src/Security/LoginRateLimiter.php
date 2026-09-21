<?php

declare(strict_types=1);

namespace App\Security;

use DateTimeImmutable;
use DateTimeZone;
use Infrastructure\Platform\Persistence\Pdo\PdoConnection;
use PDO;
use RuntimeException;
use Throwable;

final readonly class LoginRateLimiter
{
    public function __construct(
        private PdoConnection $database,
    ) {
    }

    public function consume(
        string $identity,
        int $limit = 5,
        int $windowSeconds = 300,
        int $blockSeconds = 900,
    ): RateLimitDecision {
        if ($limit < 1 || $windowSeconds < 1 || $blockSeconds < 1) {
            throw new RuntimeException('Invalid login rate-limit policy.');
        }

        $bucket = 'auth.login';
        $keyHash = hash('sha256', $bucket . ':' . trim($identity));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $windowStart = $now->modify('-' . $windowSeconds . ' seconds');

        $pdo = $this->database->connection();
        $pdo->beginTransaction();

        try {
            $statement = $pdo->prepare(
                'SELECT attempts, window_started_at, blocked_until
                 FROM cos_security_rate_limits
                 WHERE key_hash = :key_hash
                 LIMIT 1
                 FOR UPDATE'
            );
            $statement->execute(['key_hash' => $keyHash]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);

            if (is_array($row)) {
                $blockedUntil = $this->date((string) ($row['blocked_until'] ?? ''));
                if ($blockedUntil !== null && $blockedUntil > $now) {
                    $pdo->commit();

                    return new RateLimitDecision(false, 0, $blockedUntil);
                }
            }

            $attempts = is_array($row) ? (int) ($row['attempts'] ?? 0) : 0;
            $startedAt = is_array($row) ? $this->date((string) ($row['window_started_at'] ?? '')) : null;

            if ($startedAt === null || $startedAt < $windowStart) {
                $attempts = 0;
                $startedAt = $now;
            }

            ++$attempts;
            $blockedUntil = null;
            $allowed = $attempts <= $limit;

            if (!$allowed) {
                $blockedUntil = $now->modify('+' . $blockSeconds . ' seconds');
            }

            $upsert = $pdo->prepare(
                'INSERT INTO cos_security_rate_limits
                    (key_hash, bucket, attempts, window_started_at, blocked_until)
                 VALUES
                    (:key_hash, :bucket, :attempts, :window_started_at, :blocked_until)
                 ON DUPLICATE KEY UPDATE
                    bucket = VALUES(bucket),
                    attempts = VALUES(attempts),
                    window_started_at = VALUES(window_started_at),
                    blocked_until = VALUES(blocked_until)'
            );
            $upsert->execute([
                'key_hash' => $keyHash,
                'bucket' => $bucket,
                'attempts' => $attempts,
                'window_started_at' => $startedAt->format('Y-m-d H:i:s.u'),
                'blocked_until' => $blockedUntil?->format('Y-m-d H:i:s.u'),
            ]);

            $pdo->commit();

            return new RateLimitDecision(
                allowed: $allowed,
                remaining: max(0, $limit - $attempts),
                retryAt: $blockedUntil,
            );
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $error;
        }
    }

    public function clear(string $identity): void
    {
        $keyHash = hash('sha256', 'auth.login:' . trim($identity));

        $statement = $this->database->connection()->prepare(
            'DELETE FROM cos_security_rate_limits WHERE key_hash = :key_hash'
        );
        $statement->execute(['key_hash' => $keyHash]);
    }

    private function date(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
