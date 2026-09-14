<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Evaluation;
final class EvaluationMetrics
{
    public function classification(array $expected,array $actual,array $universe=[]):ClassificationMetrics{$expected=array_fill_keys(array_unique($expected),true);$actual=array_fill_keys(array_unique($actual),true);$tp=count(array_intersect_key($actual,$expected));$fp=count(array_diff_key($actual,$expected));$fn=count(array_diff_key($expected,$actual));$tn=max(0,count(array_unique($universe))-$tp-$fp-$fn);$precision=$tp+$fp? $tp/($tp+$fp):1;$recall=$tp+$fn?$tp/($tp+$fn):1;$fpr=$fp+$tn?$fp/($fp+$tn):0;$accuracy=$tp+$fp+$fn+$tn?($tp+$tn)/($tp+$fp+$fn+$tn):1;$hallucination=count($actual)?$fp/count($actual):0;return new ClassificationMetrics(round($precision,4),round($recall,4),round($fpr,4),round($accuracy,4),round($hallucination,4));}
    public function relevance(array $expected,array $ranked,int $limit=5):float{$selected=array_slice($ranked,0,$limit);return $selected===[]?0:round(count(array_intersect($expected,$selected))/count($selected),4);}
    public function redundancy(array $questions):float{return $questions===[]?0:round(1-count(array_unique($questions))/count($questions),4);}
}
