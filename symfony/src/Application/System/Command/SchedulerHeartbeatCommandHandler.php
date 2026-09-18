<?php
declare(strict_types=1);

namespace App\Application\System\Command;

use App\Application\System\Contract\RuntimeHeartbeatSinkInterface;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class SchedulerHeartbeatCommandHandler implements CommandHandlerInterface
{
    public function __construct(private RuntimeHeartbeatSinkInterface $heartbeats)
    {
    }

    public function __invoke(SchedulerHeartbeatCommand $command): void
    {
        $this->heartbeats->record('messenger-worker', $command->token);
    }
}
