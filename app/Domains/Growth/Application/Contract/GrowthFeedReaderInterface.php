<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use Domains\Growth\Application\DTO\ExternalFeedEntry;

interface GrowthFeedReaderInterface
{
    /** @return list<ExternalFeedEntry> */
    public function read(string $organizationId,string $url,int $limit):array;
}
