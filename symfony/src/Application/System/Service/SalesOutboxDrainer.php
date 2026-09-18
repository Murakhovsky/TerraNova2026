<?php
declare(strict_types=1);

namespace App\Application\System\Service;

use Kernel\Event\Service\OutboxPublisher;

final readonly class SalesOutboxDrainer
{
    public function __construct(private OutboxPublisher $publisher)
    {
    }

    public function drain(
        int $limit = 200,
        ?string $workerId = null,
        int $idleRetries = 2,
        int $idleDelayMicroseconds = 25_000,
    ): int {
        $limit = max(1, min(5000, $limit));
        $workerId = trim((string) $workerId);
        if ($workerId === '') {
            $workerId = 'symfony-sales-outbox-' . getmypid();
        }

        $processed = 0;
        $idle = 0;
        $idleRetries = max(0, min(10, $idleRetries));
        $idleDelayMicroseconds = max(0, min(250_000, $idleDelayMicroseconds));

        while ($processed < $limit) {
            if ($this->publisher->runOne($workerId)) {
                ++$processed;
                $idle = 0;
                continue;
            }

            if ($idle >= $idleRetries) {
                break;
            }

            ++$idle;
            if ($idleDelayMicroseconds > 0) {
                usleep($idleDelayMicroseconds);
            }
        }

        return $processed;
    }
}
