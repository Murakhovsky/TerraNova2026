<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Evaluation;
final class CostEvaluator
{
    public function evaluate(array $audit,int $turns,int $facts,int $findings):CostReport{$calls=count($audit);$input=array_sum(array_column($audit,'tokens_input'));$output=array_sum(array_column($audit,'tokens_output'));$cost=(float)array_sum(array_column($audit,'estimated_cost'));$latency=(int)array_sum(array_column($audit,'duration_ms'));$failures=count(array_filter($audit,fn($x)=>($x['status']??'')!=='SUCCESS'));$per=fn(int $n):float=>$n>0?round($cost/$n,6):0;return new CostReport($calls,$input,$output,round($cost,6),$latency,$failures,$per($turns),$per($facts),$per($findings));}
}
