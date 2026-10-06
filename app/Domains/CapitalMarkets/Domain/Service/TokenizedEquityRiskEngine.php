<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use DateTimeImmutable;
use DomainException;
use Domains\CapitalMarkets\Domain\Opportunity\Opportunity;
use Domains\CapitalMarkets\Domain\Opportunity\SpreadCandidate;
use Domains\CapitalMarkets\Domain\Risk\RiskAssessment;
use Domains\CapitalMarkets\Domain\Risk\RiskDecision;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;

final class TokenizedEquityRiskEngine
{
    public function assess(
        Opportunity $opportunity,
        Decimal $requestedQuantity,
        Decimal $maxTradeNotional,
        int $maxRiskScore,
        DateTimeImmutable $now,
        bool $hedgeAvailable=true,
        bool $killSwitch=false,
        ?Decimal $minimumExecutionProbability=null,
    ):RiskAssessment{
        if(!$opportunity->candidate instanceof SpreadCandidate||$opportunity->economics===null){
            throw new DomainException('TOKENIZED_EQUITY_RISK_REQUIRES_SPREAD_OPPORTUNITY');
        }
        $blocking=[];$warnings=[];
        if($killSwitch)$blocking[]='KILL_SWITCH_ACTIVE';
        if($opportunity->candidate->expiredAt($now))$blocking[]='OPPORTUNITY_EXPIRED';
        if(!$opportunity->economics->expectedNetPnl->isPositive())$blocking[]='NO_EXECUTABLE_EDGE';
        if(!$requestedQuantity->isPositive())$blocking[]='INVALID_QUANTITY';
        if(!$hedgeAvailable)$blocking[]='HEDGE_UNAVAILABLE';
        if($minimumExecutionProbability!==null&&$opportunity->executionProbability->compareTo($minimumExecutionProbability)<0)$blocking[]='EXECUTION_PROBABILITY_TOO_LOW';
        if($opportunity->riskScore>$maxRiskScore)$blocking[]='RISK_SCORE_LIMIT';

        $notional=DecimalMath::multiply($opportunity->candidate->buyPrice,$requestedQuantity);
        $approvedQuantity=$requestedQuantity;
        $approvedNotional=$notional;
        $decision=RiskDecision::Approve;

        if($notional->compareTo($maxTradeNotional)>0){
            $approvedNotional=$maxTradeNotional;
            $approvedQuantity=$opportunity->candidate->buyPrice->isZero()
                ? Decimal::fromString('0')
                : DecimalMath::divide($maxTradeNotional,$opportunity->candidate->buyPrice,12);
            $decision=RiskDecision::ApproveWithLimit;
            $warnings[]='MAX_TRADE_NOTIONAL_APPLIED';
        }

        if($blocking!==[]){
            $decision=$killSwitch?RiskDecision::Stop:RiskDecision::Reject;
            $approvedQuantity=Decimal::fromString('0');
            $approvedNotional=Decimal::fromString('0');
        }

        return new RiskAssessment(
            'cm_risk_'.hash('sha256',$opportunity->id.'|'.$now->format('U.u')),
            $opportunity->id,$decision,$opportunity->riskScore,$approvedQuantity,$approvedNotional,$now,$blocking,$warnings,
        );
    }
}
