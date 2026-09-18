<?php
declare(strict_types=1);

namespace App\Application\Sales\Command;

use DateTimeImmutable;
use Domains\Sales\Application\Service\SalesAutomationRunner;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class RunSalesAutomationCommandHandler implements CommandHandlerInterface
{
    public function __construct(private SalesAutomationRunner $automation)
    {
    }

    /** @return array<string,mixed> */
    public function __invoke(RunSalesAutomationCommand $command): array
    {
        return $this->automation->run(
            $command->organizationId,
            new DateTimeImmutable(),
            max(1, $command->noActivityHours),
            max(1, min(1000, $command->limit)),
        );
    }
}
