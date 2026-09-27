<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use Domains\Growth\Application\DTO\ExternalJsonSignalEntry;
use Domains\Growth\Domain\GrowthJsonSignalSource;

interface GrowthJsonSignalReaderInterface
{
    /** @return list<ExternalJsonSignalEntry> */
    public function read(GrowthJsonSignalSource $source,int $limit):array;
}
