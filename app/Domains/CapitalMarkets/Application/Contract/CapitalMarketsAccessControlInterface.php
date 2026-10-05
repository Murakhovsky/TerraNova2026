<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

interface CapitalMarketsAccessControlInterface
{
    public function hasCapability(string $organizationId,int $userId,string $capability):bool;
}
