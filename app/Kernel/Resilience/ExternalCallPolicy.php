<?php
declare(strict_types=1);

namespace Kernel\Resilience;

use InvalidArgumentException;

final readonly class ExternalCallPolicy
{
    public function __construct(
        public int $maxAttempts = 3,
        public int $baseDelayMilliseconds = 100,
        public int $maxDelayMilliseconds = 2000,
        public int $failureThreshold = 5,
        public int $circuitOpenSeconds = 60,
    ) {
        if ($this->maxAttempts < 1 || $this->maxAttempts > 20) {
            throw new InvalidArgumentException('External call max attempts must be between 1 and 20.');
        }
        if ($this->baseDelayMilliseconds < 0 || $this->baseDelayMilliseconds > 60_000) {
            throw new InvalidArgumentException('External call base delay must be between 0 and 60000 ms.');
        }
        if ($this->maxDelayMilliseconds < $this->baseDelayMilliseconds || $this->maxDelayMilliseconds > 300_000) {
            throw new InvalidArgumentException('External call max delay must be >= base delay and <= 300000 ms.');
        }
        if ($this->failureThreshold < 1 || $this->failureThreshold > 1000) {
            throw new InvalidArgumentException('External circuit failure threshold must be between 1 and 1000.');
        }
        if ($this->circuitOpenSeconds < 1 || $this->circuitOpenSeconds > 86400) {
            throw new InvalidArgumentException('External circuit open duration must be between 1 and 86400 seconds.');
        }
    }

    public function delayMilliseconds(int $failedAttempt): int
    {
        if ($this->baseDelayMilliseconds === 0) return 0;

        $exponent = max(0, min(20, $failedAttempt - 1));
        $delay = min(
            $this->maxDelayMilliseconds,
            $this->baseDelayMilliseconds * (2 ** $exponent),
        );
        $jitter = max(1, (int) floor($delay * 0.2));

        return min($this->maxDelayMilliseconds, $delay + random_int(0, $jitter));
    }
}
