<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Interview;

use Domains\Diagnostic\Model\Hypothesis;
use Domains\Diagnostic\Model\HypothesisStatus;
use Domains\Diagnostic\Model\RootCause;

final class RootCauseAnalysisService
{
    /** @return list<Hypothesis> */
    public function confirm(
        array $hypotheses,
        float $coverage,
        float $minimumCoverage = .75,
        int $minimumEvidence = 2,
        float $minimumConfidence = .75,
        float $maximumContradiction = .25,
    ): array {
        if ($coverage < $minimumCoverage) return [];

        $confirmed = [];
        foreach ($hypotheses as $hypothesis) {
            if (!$hypothesis instanceof Hypothesis) continue;
            if ($hypothesis->status === HypothesisStatus::ConfirmedRootCause) {
                $confirmed[] = $hypothesis;
                continue;
            }
            if (!in_array($hypothesis->status, [HypothesisStatus::Supported, HypothesisStatus::StronglySupported], true)) continue;

            $support = count(array_unique($hypothesis->supportingEvidence));
            $contradiction = count(array_unique($hypothesis->contradictingEvidence));
            $contradictionRatio = ($support + $contradiction) > 0 ? $contradiction / ($support + $contradiction) : 1.0;
            if ($support < $minimumEvidence || $hypothesis->confidence < $minimumConfidence || $contradictionRatio > $maximumContradiction) continue;

            $candidate = $hypothesis->status === HypothesisStatus::Supported
                ? $hypothesis->transition(HypothesisStatus::StronglySupported, $hypothesis->confidence)
                : $hypothesis;
            $confirmed[] = $candidate->transition(HypothesisStatus::ConfirmedRootCause, $candidate->confidence);
        }

        return $confirmed;
    }

    public function analyze(array $hypotheses,array $findingIds,float $coverage,float $minimumCoverage=.75,int $minimumEvidence=2):array
    {
        $confirmed=$this->confirm($hypotheses,$coverage,$minimumCoverage,$minimumEvidence);
        $causes=[];
        foreach($confirmed as $hypothesis){
            $causes[]=new RootCause(
                'root:'.$hypothesis->id,
                $hypothesis->statement,
                $findingIds,
                $hypothesis->supportingEvidence,
                min(.95,$hypothesis->confidence),
                ['finding','hypothesis:'.$hypothesis->id,'root:'.$hypothesis->id],
            );
        }
        return $causes;
    }
}
