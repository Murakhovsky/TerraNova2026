<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Report;
use Domains\Diagnostic\Model\Recommendation;
final readonly class RecommendationGenerationService
{
    public function __construct(private PriorityEngine $priority=new PriorityEngine()){}
    /** Templates are validated methodology definitions, never free-form AI interventions. */
    public function generate(array $templates,array $findingIds,array $rootCauseIds):array{$out=[];foreach($templates as $t){$triggers=array_values(array_intersect($findingIds,$t['finding_ids']??[]));$causes=array_values(array_intersect($rootCauseIds,$t['root_cause_ids']??[]));if($triggers===[]&&$causes===[])continue;$v=$t['priority_inputs']??[];$score=$this->priority->score((float)($v['impact']??.5),(float)($v['confidence']??.5),(float)($v['urgency']??.5),(float)($v['effort']??.5),(float)($v['cost']??.5),(float)($v['time']??.5),(float)($v['risk']??.5));$quick=$this->priority->isQuickWin($score,(float)($v['impact']??.5),(float)($v['confidence']??.5),(float)($v['effort']??.5),(float)($v['time']??.5));$out[]=new Recommendation((string)$t['id'],$triggers,$causes,$score>=35?'HIGH':($score>=15?'MEDIUM':'LOW'),(string)($t['expected_impact']??'UNKNOWN'),(string)($t['effort']??'medium'),(string)$t['rationale'],array_values($t['actions']??[]),array_values($t['success_metrics']??[]),RecommendationStatus::Proposed,$score,['quick_win'=>$quick,'owner_role'=>$t['owner_role']??'Sales Manager','dependencies'=>$t['dependencies']??[],'expected_outcome'=>$t['expected_outcome']??'']);}usort($out,fn($a,$b)=>$b->priorityScore<=>$a->priorityScore);return $out;}
}
