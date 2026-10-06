<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

use Domains\CapitalMarkets\Domain\MarketData\MarketSnapshot;

interface MarketSnapshotRepositoryInterface
{
    public function save(string $organizationId,MarketSnapshot $snapshot):void;
    public function get(string $organizationId,string $snapshotId):?MarketSnapshot;
}
