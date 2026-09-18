<?php
declare(strict_types=1);

namespace App\Application\Sales\Command;

use App\Application\System\Command\DrainSalesOutboxCommand;
use DateTimeImmutable;
use Domains\Sales\Application\Service\SalesAutomationRunner;
use Kernel\Application\Command\CommandHandlerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class RunSalesAutomationCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private SalesAutomationRunner $automation,
        private MessageBusInterface $commandBus,
    ) {
    }

    /** @return array<string,mixed> */
    public function __invoke(RunSalesAutomationCommand $command): array
    {
        $result = $this->automation->run(
            $command->organizationId,
            new DateTimeImmutable(),
            max(1, $command->noActivityHours),
            max(1, min(1000, $command->limit)),
        );

        // Detector events are committed before this point. Queue the Sales-only
        // Outbox drain causally after the scan so multiple Messenger workers
        // cannot drain first and strand newly-created detector events until the
        // next periodic scheduler tick.
        $this->commandBus->dispatch(new DrainSalesOutboxCommand(
            max(1, min(1000, $command->limit)),
            'sales-automation:' . ($command->runId ?? 'run'),
        ));

        return $result;
    }
}
