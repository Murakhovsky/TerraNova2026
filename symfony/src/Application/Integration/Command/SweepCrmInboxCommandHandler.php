<?php
declare(strict_types=1);

namespace App\Application\Integration\Command;

use Domains\Sales\Application\Contract\CrmInboxPendingRepositoryInterface;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class SweepCrmInboxCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CrmInboxPendingRepositoryInterface $inbox,
        private CommandBusInterface $commands,
    ) {
    }

    /** @return array<string,int> */
    public function __invoke(SweepCrmInboxCommand $command): array
    {
        $items = $this->inbox->pending(max(1, min(500, $command->limit)));
        foreach ($items as $item) {
            $this->commands->dispatch(new ProcessCrmInboxCommand(
                $item['organization_id'],
                $item['id'],
                $item['correlation_id'],
            ));
        }

        return ['scheduled' => count($items)];
    }
}
