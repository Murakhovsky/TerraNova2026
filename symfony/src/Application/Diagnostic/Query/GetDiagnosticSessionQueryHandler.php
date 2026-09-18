<?php
declare(strict_types=1); namespace App\Application\Diagnostic\Query;
use Domains\Diagnostic\Application\Service\DiagnosticRuntimeService; use Kernel\Application\Query\QueryHandlerInterface;
final readonly class GetDiagnosticSessionQueryHandler implements QueryHandlerInterface { public function __construct(private DiagnosticRuntimeService $runtime){} public function __invoke(GetDiagnosticSessionQuery $q):array{return $this->runtime->resume($q->organizationId->value(),$q->sessionId);} }
