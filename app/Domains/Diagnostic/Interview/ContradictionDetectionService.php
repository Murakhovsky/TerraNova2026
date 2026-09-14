<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Interview;
use Domains\Diagnostic\Model\Fact;
final class ContradictionDetectionService
{
    public function detect(array $existingFacts,array $candidates):array{$byKey=[];foreach($existingFacts as $fact)if($fact instanceof Fact)$byKey[$fact->key]=$fact;$result=[];foreach($candidates as $candidate){if(!$candidate instanceof ExtractedFact||!isset($byKey[$candidate->key])||$byKey[$candidate->key]->value===$candidate->value)continue;$old=$byKey[$candidate->key];$result[]=new Contradiction(substr(hash('sha256',$candidate->key.':'.json_encode([$old->value,$candidate->value])),0,32),$candidate->key.' = '.json_encode($old->value),$candidate->key.' = '.json_encode($candidate->value),$old->evidenceIds,$candidate->evidenceIds,$old->confidence>=.8&&$candidate->confidence>=.8?'HIGH':'MEDIUM','OPEN','Which value is valid for the same scope and period?');}return $result;}
}
