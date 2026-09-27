<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use Domains\Growth\Application\DTO\GrowthMarketDiscoveryBatch;
use Domains\Growth\Domain\GrowthMarketUniverse;

interface GrowthMarketSourceInterface
{
    public function type():string;
    public function discover(GrowthMarketUniverse $universe,?string $cursor,int $limit):GrowthMarketDiscoveryBatch;
}
