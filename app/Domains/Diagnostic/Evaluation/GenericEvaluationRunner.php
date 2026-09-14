<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Evaluation;

use Domains\Diagnostic\Methodology\CompiledDiagnosticPack;
use Domains\Diagnostic\Methodology\Engine\MethodologyEngine;
use Domains\Diagnostic\Methodology\Input\DiagnosticInput;

final readonly class GenericEvaluationRunner
{
    public function __construct(private MethodologyEngine $engine = new MethodologyEngine()) {}

    /** @param iterable<array{id?:string,input:DiagnosticInput,expected?:array}> $scenarios */
    public function run(CompiledDiagnosticPack $pack, iterable $scenarios): array
    {
        $summary=['passed'=>0,'failed'=>0,'results'=>[]];
        foreach($scenarios as $i=>$scenario){
            $id=(string)($scenario['id']??$i); $expected=$scenario['expected']??[];
            try{
                $r=$this->engine->evaluate($scenario['input'],$pack->pack);
                $findings=array_map(fn($f)=>$f->ruleId,$r->findings);
                $missing=array_values(array_diff($expected['findings']??[],$findings));
                $scoreOk=!isset($expected['score_min'])||$r->score>=(float)$expected['score_min'];
                $status=$missing===[]&&$scoreOk?'PASSED':'FAILED';
                $details=['score'=>$r->score,'coverage'=>$r->coverage->ratio,'confidence'=>$r->confidence,'missing_findings'=>$missing];
            }catch(\Throwable $e){$status='FAILED';$details=['error'=>$e->getMessage()];}
            $summary[strtolower($status)]++; $summary['results'][]=['id'=>$id,'status'=>$status,'details'=>$details];
        }
        return $summary;
    }
}
