<?php
declare(strict_types=1); namespace App\Application\Diagnostic\Query;
use Domains\Diagnostic\Application\Service\DiagnosticRuntimeService; use Kernel\Application\Query\QueryHandlerInterface;
final readonly class GetDiagnosticAssessmentQueryHandler implements QueryHandlerInterface
{
    public function __construct(private DiagnosticRuntimeService $runtime){}
    public function __invoke(GetDiagnosticAssessmentQuery $q):array
    {
        $stored=$this->runtime->report($q->organizationId->value(),$q->sessionId); $r=is_array($stored['report']??null)?$stored['report']:[];
        return ['overall_health'=>$r['overallHealth']??null,'coverage'=>$r['coverage']??null,'confidence'=>$r['confidence']??null,'area_scores'=>$r['areaScores']??[],'metrics'=>$r['metrics']??[],'report_version'=>$stored['report_version']??null];
    }
}
