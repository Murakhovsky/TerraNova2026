<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Evaluation;
use Domains\Diagnostic\Model\DiagnosticState;
final class DiagnosticComparisonService
{
    public function compare(DiagnosticState $before,DiagnosticState $after):DiagnosticComparison{$scoreChanges=[];foreach(($after->scores['sections']??[]) as $id=>$score){$old=$before->scores['sections'][$id]??null;$scoreChanges[$id]=$old===null||$score===null?null:round($score-$old,2);}$delta=(float)($after->scores['pack']??0)-(float)($before->scores['pack']??0);$status=abs($delta)<.01?'UNCHANGED':($delta>0?'IMPROVED':'WORSENED');$ids=fn(array $xs)=>array_values(array_map(fn($x)=>(string)($x->id??$x->ruleId??''),$xs));$old=$ids($before->findings);$new=$ids($after->findings);return new DiagnosticComparison($before->diagnosticId,$after->diagnosticId,$status,$scoreChanges,round((float)($after->coverage['pack']??0)-(float)($before->coverage['pack']??0),4),array_values(array_diff($old,$new)),array_values(array_diff($new,$old)),[]);}
}
