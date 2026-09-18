<?php
declare(strict_types=1);

namespace App\Application\System\Command;

use Kernel\Application\Command\CommandHandlerInterface;
use Kernel\Event\Service\OutboxPublisher;

final readonly class DrainSalesOutboxCommandHandler implements CommandHandlerInterface
{
    public function __construct(private OutboxPublisher $publisher)
    {
    }

    public function __invoke(DrainSalesOutboxCommand $command): int
    {
        $workerId = trim((string) $command->workerId);
        if ($workerId === '') {
            $workerId = 'symfony-sales-outbox-' . getmypid();
        }

        $processed = 0;
        $limit = max(1, min(1000, $command->limit));
        while ($processed < $limit && $this->publisher->runOne($workerId)) {
            $processed++;
        }

        return $processed;
    }
}
