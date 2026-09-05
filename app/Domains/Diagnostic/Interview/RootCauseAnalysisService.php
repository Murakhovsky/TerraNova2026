<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Interview;
use Domains\Diagnostic\Model\{Hypothesis,HypothesisStatus,RootCause};

final class RootCauseAnalysisService
{
    public function analyze(array $hypotheses,array $findingIds,float $coverage,float $minimumCoverage=.75,int $minimumEvidence=2):array
    {
        if($coverage<$minimumCoverage)return [];$causes=[];foreach($hypotheses as $hypothesis){if(!$hypothesis instanceof Hypothesis||$hypothesis->status!==HypothesisStatus::Supported||count(array_unique($hypothesis->supportingEvidence))<$minimumEvidence||$hypothesis->confidence<.75)continue;$causes[]=new RootCause('root:'.$hypothesis->id,$hypothesis->statement,$findingIds,$hypothesis->supportingEvidence,min(.95,$hypothesis->confidence),['finding','hypothesis:'.$hypothesis->id,'root:'.$hypothesis->id]);}return $causes;
    }
}
