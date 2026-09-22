<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class QualificationPolicyEvaluator
{
    public function evaluate(
        string $candidateId,
        QualificationPolicy $policy,
        OpportunityRationale $rationale,
        OpportunityScore $score,
        DateTimeImmutable $evaluatedAt,
    ): QualificationEvaluation {
        if($policy->status()!==QualificationPolicyStatus::Active){
            throw new InvalidArgumentException('Growth qualification evaluation requires an active policy.');
        }

        $dimensions=[
            'fit'=>$score->fit->score,
            'need'=>$score->need->score,
            'timing'=>$score->timing->score,
            'access'=>$score->access->score,
            'value'=>$score->value->score,
        ];

        $hardFailures=[];
        foreach($policy->criteria->hardRejectBelow as $dimension=>$threshold){
            if($dimensions[$dimension]<$threshold)$hardFailures[]=$dimension.'.hard_reject';
        }
        if($hardFailures!==[]){
            return new QualificationEvaluation(
                $candidateId,$policy->id,$policy->revision,$rationale,$score,QualificationOutcome::Disqualified,
                $hardFailures,'One or more hard-reject thresholds were not met.',$evaluatedAt,
            );
        }

        $qualificationFailures=[];
        foreach($policy->criteria->qualifyMinimums as $dimension=>$threshold){
            if($dimensions[$dimension]<$threshold)$qualificationFailures[]=$dimension.'.minimum';
        }
        if($score->confidence<$policy->criteria->minConfidence)$qualificationFailures[]='score_confidence.minimum';
        if($rationale->confidence<$policy->criteria->minConfidence)$qualificationFailures[]='rationale_confidence.minimum';

        if($qualificationFailures===[]){
            return new QualificationEvaluation(
                $candidateId,$policy->id,$policy->revision,$rationale,$score,QualificationOutcome::Qualified,
                [],'All qualification thresholds were met.',$evaluatedAt,
            );
        }

        return new QualificationEvaluation(
            $candidateId,$policy->id,$policy->revision,$rationale,$score,QualificationOutcome::Monitor,
            $qualificationFailures,'Candidate remains plausible but does not meet all qualification thresholds.',$evaluatedAt,
        );
    }
}
