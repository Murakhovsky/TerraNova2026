<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Report;
use Domains\Diagnostic\Model\Recommendation;
final class RoadmapBuilder
{
    /** @return list<RoadmapItem> */
    public function build(array $recommendations):array{$items=[];usort($recommendations,fn($a,$b)=>$b->priorityScore<=>$a->priorityScore);foreach($recommendations as $i=>$r){if(!$r instanceof Recommendation)continue;$period=match(true){$i<3=>'0-14 days',$i<6=>'15-30 days',$i<10=>'31-60 days',default=>'61-90 days'};foreach($r->actions as $action)$items[]=new RoadmapItem($period,$r->id,(string)$action,(string)($r->details['owner_role']??'Sales Manager'),$r->details['dependencies']??[],(string)($r->successMetrics[0]??''),(string)($r->details['expected_outcome']??$r->impact));}return $items;}
}
