<?php
declare(strict_types=1);

namespace App\Application\Sales\Command;

use App\Application\System\Service\SalesOutboxDrainer;
use DateTimeImmutable;
use Domains\Sales\Application\Service\SalesAutomationRunner;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class RunSalesAutomationCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private SalesAutomationRunner $automation,
        private SalesOutboxDrainer $outbox,
    ) {
    }

    /** @return array<string,mixed> */
    public function __invoke(RunSalesAutomationCommand $command): array
    {
        $limit = max(1, min(1000, $command->limit));
        $result = $this->automation->run(
            $command->organizationId,
            new DateTimeImmutable(),
            max(1, $command->noActivityHours),
            $limit,
        );

        // This command already runs inside the async worker. Drain the Sales outbox
        // in the same causal execution after detector transactions have committed.
        // A separate queued drain creates an avoidable ordering/race window.
        $result['outbox_published'] = $this->outbox->drain(
            $limit,
            'sales-automation:' . ($command->runId ?? 'run'),
        );

        return $result;
    }
}
