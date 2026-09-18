<?php
declare(strict_types=1); namespace App\Application\Diagnostic\Query;
use Domains\Diagnostic\Application\Service\DiagnosticRuntimeService; use Kernel\Application\Query\QueryHandlerInterface;
final readonly class GetDiagnosticReportQueryHandler implements QueryHandlerInterface { public function __construct(private DiagnosticRuntimeService $runtime){} public function __invoke(GetDiagnosticReportQuery $q):array{return $this->runtime->report($q->organizationId->value(),$q->sessionId);} }
