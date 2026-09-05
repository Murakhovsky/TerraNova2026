<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Evaluation;
final readonly class DiagnosticComparison { public function __construct(public string $previousDiagnosticId,public string $currentDiagnosticId,public string $outcome,public array $scoreChanges,public float $coverageChange,public array $resolvedFindings,public array $newFindings,public array $recommendationChanges){} }
