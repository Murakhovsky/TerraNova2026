<?php
declare(strict_types=1); namespace App\Application\Diagnostic\Query;
use Domains\Diagnostic\Application\Service\DiagnosticRuntimeService; use Kernel\Application\Query\QueryHandlerInterface;
final readonly class GetDiagnosticFindingsQueryHandler implements QueryHandlerInterface
{
    public function __construct(private DiagnosticRuntimeService $runtime){}
    public function __invoke(GetDiagnosticFindingsQuery $q):array{$stored=$this->runtime->report($q->organizationId->value(),$q->sessionId);$r=is_array($stored['report']??null)?$stored['report']:[];return ['items'=>array_values($r['findings']??[])];}
}
