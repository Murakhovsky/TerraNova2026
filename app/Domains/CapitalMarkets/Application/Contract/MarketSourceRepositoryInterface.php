<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceHealth;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;

interface MarketSourceRepositoryInterface
{
    public function save(string $organizationId,MarketSourceDescriptor $source):void;
    public function get(string $organizationId,MarketSourceId $id):?MarketSourceDescriptor;

    /** @return list<MarketSourceDescriptor> */
    public function list(string $organizationId,bool $enabledOnly=false):array;

    public function saveHealth(string $organizationId,MarketSourceHealth $health):void;
    public function health(string $organizationId,MarketSourceId $id):?MarketSourceHealth;
}
