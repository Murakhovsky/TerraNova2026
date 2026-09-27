<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class SignalPollingBackoffPolicy
{
    public function __construct(
        private int $baseMinutes,
        private int $maxMinutes = 360,
    ) {
        if($baseMinutes<1||$baseMinutes>1440){
            throw new InvalidArgumentException('Growth polling backoff base must be between 1 and 1440 minutes.');
        }
        if($maxMinutes<$baseMinutes||$maxMinutes>10080){
            throw new InvalidArgumentException('Growth polling max backoff must be between base cadence and 10080 minutes.');
        }
    }

    public function delayMinutes(int $consecutiveFailures): int
    {
        if($consecutiveFailures<1){
            throw new InvalidArgumentException('Growth polling failure count must be positive.');
        }
        $exponent=min(10,$consecutiveFailures-1);
        return min($this->maxMinutes,$this->baseMinutes*(2**$exponent));
    }

    public function nextRetryAt(DateTimeImmutable $failedAt,int $consecutiveFailures): DateTimeImmutable
    {
        return $failedAt->modify('+'.$this->delayMinutes($consecutiveFailures).' minutes');
    }

    public function isCoolingDown(?DateTimeImmutable $nextRetryAt,DateTimeImmutable $now): bool
    {
        return $nextRetryAt!==null&&$nextRetryAt>$now;
    }
}
