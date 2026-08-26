<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

use Domains\Sales\Application\DTO\CreateTaskCommand;
use Domains\Sales\Application\DTO\OperationResult;
use Domains\Sales\Application\DTO\SendMessageCommand;
use Domains\Sales\Application\DTO\ScheduleFollowupCommand;
use Domains\Sales\Model\DealChangeSet;

interface CrmProviderInterface
{
    public function provider(): string;

    public function createTask(CreateTaskCommand $command): OperationResult;

    public function send(SendMessageCommand $command): OperationResult;

    public function schedule(ScheduleFollowupCommand $command): OperationResult;

    public function update(string $organizationId, string $dealReference, DealChangeSet $changes): OperationResult;
}
