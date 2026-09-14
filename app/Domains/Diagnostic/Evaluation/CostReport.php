<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Evaluation;
final readonly class CostReport { public function __construct(public int $calls,public int $inputTokens,public int $outputTokens,public float $cost,public int $latencyMs,public int $failures,public float $costPerTurn,public float $costPerExtractedFact,public float $costPerUsefulFinding){} }
