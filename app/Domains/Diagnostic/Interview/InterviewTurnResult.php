<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Interview;
use Domains\Diagnostic\Model\DiagnosticState;
final readonly class InterviewTurnResult { public function __construct(public array $facts,public array $evidence,public array $contradictions,public DiagnosticState $state,public ?NextQuestionDecision $nextQuestion){} }
