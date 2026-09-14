<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Interview;
final readonly class NextQuestionDecision { public function __construct(public string $questionId,public string $question,public float $priorityScore,public string $reason,public array $targetCriteria,public array $targetFacts,public float $expectedInformationGain,public float $dependencyUnlockValue){} }
