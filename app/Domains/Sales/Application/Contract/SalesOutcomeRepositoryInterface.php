<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;

use Domains\Sales\Application\DTO\RecordActionOutcomeCommand;

interface SalesOutcomeRepositoryInterface
{
    public function record(RecordActionOutcomeCommand $command): string;
}
