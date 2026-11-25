<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

use Domains\Sales\Application\DTO\ChangeDealStageCommand;

/** Verifies the exact immutable approved stage mutation against the locked Deal. */
interface SalesStageApprovalVerifier
{
    public function assertApproved(ChangeDealStageCommand $command,array $lockedDeal):void;
}
