<?php
declare(strict_types=1);

namespace Kernel\Operations\Service;

final class PeriodicMaintenanceGate
{
    private ?int $lastRunNanoseconds = null;

    public function __construct(private readonly int $intervalSeconds = 30)
    {
        if ($this->intervalSeconds < 0) {
            throw new \InvalidArgumentException('Maintenance interval cannot be negative.');
        }
    }

    public function due(): bool
    {
        $now = hrtime(true);
        if ($this->lastRunNanoseconds === null
            || $this->intervalSeconds === 0
            || $now - $this->lastRunNanoseconds >= $this->intervalSeconds * 1_000_000_000) {
            $this->lastRunNanoseconds = $now;
            return true;
        }

        return false;
    }
}
