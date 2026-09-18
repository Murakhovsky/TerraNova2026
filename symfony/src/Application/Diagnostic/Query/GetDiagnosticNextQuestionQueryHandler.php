<?php
declare(strict_types=1); namespace App\Application\Diagnostic\Query;
use Domains\Diagnostic\Application\Service\DiagnosticRuntimeService; use Kernel\Application\Query\QueryHandlerInterface;
final readonly class GetDiagnosticNextQuestionQueryHandler implements QueryHandlerInterface { public function __construct(private DiagnosticRuntimeService $runtime){} public function __invoke(GetDiagnosticNextQuestionQuery $q):array{return ['next_question'=>$this->runtime->nextQuestion($q->organizationId->value(),$q->sessionId)];} }
