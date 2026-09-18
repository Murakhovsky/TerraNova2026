<?php
declare(strict_types=1);

namespace App\Application\System\Command;

use Kernel\Application\Command\CommandHandlerInterface;
use Kernel\Queue\Handler\ActionExecutionJobHandler;
use Kernel\Queue\Job;

final readonly class ExecuteSalesActionCommandHandler implements CommandHandlerInterface
{
    public function __construct(private ActionExecutionJobHandler $actions)
    {
    }

    public function __invoke(ExecuteSalesActionCommand $command): void
    {
        $actionId = trim($command->actionId);
        $organizationId = trim($command->organizationId);
        if ($actionId === '' || $organizationId === '') {
            throw new \InvalidArgumentException('Sales action execution requires organizationId and actionId.');
        }

        $this->actions->handle(new Job(
            'symfony-action-' . $actionId,
            $organizationId,
            ActionExecutionJobHandler::TYPE,
            ['action_id' => $actionId],
            1,
            5,
            120,
            $actionId,
            'symfony-action:' . $actionId,
            'symfony-messenger',
        ));
    }
}
