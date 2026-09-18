<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

use Domains\Sales\Application\DTO\OperationResult;
use Domains\Sales\Application\DTO\ScheduleLeadFollowupCommand;

interface LeadFollowupRepositoryInterface
{
    public function schedule(ScheduleLeadFollowupCommand $command): OperationResult;
}
