<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use InvalidArgumentException;

final class CapitalVelocityEngine
{
    public function returnOnCapitalTime(Decimal $netPnl,Decimal $capital,int $holdingSeconds):Decimal
    {
        if(!$capital->isPositive()||$holdingSeconds<=0)throw new InvalidArgumentException('Capital and holding time must be positive.');
        $capitalTime=DecimalMath::multiply($capital,Decimal::fromString((string)$holdingSeconds));
        return DecimalMath::divide($netPnl,$capitalTime,18);
    }

    public function velocity(Decimal $capital,int $holdingSeconds):Decimal
    {
        if(!$capital->isPositive()||$holdingSeconds<=0)throw new InvalidArgumentException('Capital and holding time must be positive.');
        return DecimalMath::divide($capital,Decimal::fromString((string)$holdingSeconds),18);
    }
}
