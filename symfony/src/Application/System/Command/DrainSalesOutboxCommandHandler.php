<?php
declare(strict_types=1);

namespace App\Application\System\Command;

use App\Application\System\Service\SalesOutboxDrainer;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class DrainSalesOutboxCommandHandler implements CommandHandlerInterface
{
    public function __construct(private SalesOutboxDrainer $outbox)
    {
    }

    public function __invoke(DrainSalesOutboxCommand $command): int
    {
        return $this->outbox->drain(
            $command->limit,
            $command->workerId,
        );
    }
}
