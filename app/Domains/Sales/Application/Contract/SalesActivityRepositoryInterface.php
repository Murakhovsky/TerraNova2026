<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

use Domains\Sales\Application\DTO\RecordCompletedCallCommand;

interface SalesActivityRepositoryInterface
{
    /** Returns the persisted activity id. */
    public function recordCompletedCall(RecordCompletedCallCommand $command): string;
}
