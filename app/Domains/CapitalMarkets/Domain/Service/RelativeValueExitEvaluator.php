<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Strategy\ExitDecision;
use Domains\CapitalMarkets\Domain\Strategy\ExitEvaluation;
use Domains\CapitalMarkets\Domain\Strategy\RelativeValueExitPolicy;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;

final class RelativeValueExitEvaluator
{
    public function evaluate(
        DateTimeImmutable $openedAt,
        DateTimeImmutable $now,
        Decimal $entryBasisBps,
        Decimal $currentBasisBps,
        Decimal $remainingExpectedPnl,
        RelativeValueExitPolicy $policy,
        bool $fundingSignReversed=false,
        bool $dataTrusted=true,
        bool $riskBreached=false,
        bool $targetProfitReached=false,
    ):ExitEvaluation{
        $reasons=[];$emergency=[];
        if($riskBreached&&$policy->exitOnRiskBreach)$emergency[]='RISK_LIMIT_REACHED';
        if(!$dataTrusted&&$policy->exitOnDataDegradation)$emergency[]='MARKET_DATA_DEGRADED';

        $adverse=DecimalMath::subtract($currentBasisBps,$entryBasisBps);
        if(DecimalMath::abs($adverse)->compareTo($policy->maximumAdverseBasisMoveBps)>0)$emergency[]='BASIS_WIDENING_LIMIT';

        if($currentBasisBps->compareTo($policy->targetBasisBps)<=0)$reasons[]='TARGET_BASIS_REACHED';
        if($targetProfitReached)$reasons[]='TARGET_PROFIT_REACHED';
        if($remainingExpectedPnl->compareTo($policy->minimumRemainingExpectedPnl)<=0)$reasons[]='REMAINING_RETURN_TOO_LOW';
        if($fundingSignReversed&&$policy->exitOnFundingSignReversal)$reasons[]='FUNDING_CHANGED_ADVERSELY';
        if(($now->getTimestamp()-$openedAt->getTimestamp())>=$policy->maximumHoldingSeconds)$reasons[]='MAX_HOLDING_TIME_REACHED';

        if($emergency!==[])return new ExitEvaluation(ExitDecision::EmergencyExit,$emergency);
        if($reasons!==[])return new ExitEvaluation(ExitDecision::Exit,$reasons);
        return new ExitEvaluation(ExitDecision::Hold,[]);
    }
}
