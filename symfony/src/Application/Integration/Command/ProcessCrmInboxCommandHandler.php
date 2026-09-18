<?php
declare(strict_types=1);

namespace App\Application\Integration\Command;

use Domains\Sales\Application\UseCase\ProcessCrmInbox;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class ProcessCrmInboxCommandHandler implements CommandHandlerInterface
{
    public function __construct(private ProcessCrmInbox $processor)
    {
    }

    /** @return array<string,mixed> */
    public function __invoke(ProcessCrmInboxCommand $command): array
    {
        $workerId = 'symfony:' . substr(hash('sha256', $command->correlationId . ':' . $command->inboxId), 0, 24);
        $this->processor->execute($command->organizationId, $command->inboxId, $workerId);

        return [
            'inbox_id' => $command->inboxId,
            'processed' => true,
        ];
    }
}
