<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Evaluation;
final class RegressionComparator
{
    public function compare(array $baseline,array $candidate,array $critical=['accuracy','hallucination_rate']):array{$changes=[];$regressed=false;foreach(array_unique([...array_keys($baseline),...array_keys($candidate)]) as $key){$changes[$key]=round((float)($candidate[$key]??0)-(float)($baseline[$key]??0),4);if(in_array($key,$critical,true)&&(($key==='hallucination_rate'&&$changes[$key]>0)||($key!=='hallucination_rate'&&$changes[$key]<0)))$regressed=true;}return ['promotable'=>!$regressed,'changes'=>$changes];}
}
