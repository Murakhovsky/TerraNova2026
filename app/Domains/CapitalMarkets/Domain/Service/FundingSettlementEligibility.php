<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Portfolio\Position;

final class FundingSettlementEligibility
{
    public function eligible(Position $position,DateTimeImmutable $settlementAt):bool
    {
        if($position->openedAt===null||$position->openedAt>$settlementAt)return false;
        if($position->closedAt!==null&&$position->closedAt<=$settlementAt)return false;
        return !$position->quantity->isZero();
    }
}
