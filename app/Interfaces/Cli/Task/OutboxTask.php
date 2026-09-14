<?php
declare(strict_types=1);

namespace Interfaces\Cli\Task;

use Kernel\Event\Service\OutboxPublisher;
use Kernel\Event\Service\OutboxReplayService;
use Phalcon\Cli\Task;

final class OutboxTask extends Task
{
    public function runAction(?string $workerId = null, int $limit = 100): void
    {
        /** @var OutboxPublisher $publisher */
        $publisher = $this->getDI()->getShared('cosOutboxPublisher');
        $workerId ??= gethostname() . '-outbox-' . getmypid();
        $processed = 0;
        while ($processed < max(1, min($limit, 1000)) && $publisher->runOne($workerId)) {
            $processed++;
        }
        echo sprintf('Outbox processed: %d', $processed) . PHP_EOL;
    }

    public function replayAction(?string $organizationId = null, ?string $eventId = null): void
    {
        /** @var OutboxReplayService $replay */
        $replay = $this->getDI()->getShared('cosOutboxReplay');
        echo json_encode(
            $replay->replay($organizationId ?: null, $eventId ?: null),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ) . PHP_EOL;
    }
}
