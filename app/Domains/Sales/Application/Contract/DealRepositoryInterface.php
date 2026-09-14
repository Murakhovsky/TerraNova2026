<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

use Domains\Sales\Application\DTO\OperationResult;
use Domains\Sales\Model\DealChangeSet;

interface DealRepositoryInterface
{
    public function update(string $organizationId, string $dealReference, DealChangeSet $changes): OperationResult;
}
