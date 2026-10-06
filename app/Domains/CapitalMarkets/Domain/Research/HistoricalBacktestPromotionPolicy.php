<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

use Domains\CapitalMarkets\Domain\Value\Decimal;
use InvalidArgumentException;

final class HistoricalBacktestPromotionPolicy
{
    public function decide(
        int $trainDetected,
        int $oosDetected,
        Decimal $trainAverageExpectedPnl,
        Decimal $oosAverageExpectedPnl,
        Decimal $oosExecutableRatio,
        int $minimumSample,
    ):string{
        if($minimumSample<1)throw new InvalidArgumentException('Minimum backtest sample must be positive.');
        if($trainDetected<$minimumSample||$oosDetected<$minimumSample)return 'INSUFFICIENT_OOS_SAMPLE';
        if(!$trainAverageExpectedPnl->isPositive())return 'TRAIN_FAIL';
        if(!$oosAverageExpectedPnl->isPositive())return 'OOS_FAIL';
        if($oosExecutableRatio->compareTo(Decimal::fromString('0.1'))<0)return 'OOS_NOT_EXECUTABLE';
        return 'OOS_PASS';
    }
}
