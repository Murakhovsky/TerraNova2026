<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Report;
use JsonSerializable;
final readonly class DiagnosticReport implements JsonSerializable
{
    public function __construct(public string $diagnosticId,public string $executiveSummary,public ?float $overallHealth,public float $coverage,public float $confidence,public array $topProblems,public array $opportunities,public array $criticalRisks,public array $strengths,public array $quickWins,public array $areaScores,public array $metrics,public array $findings,public array $rootCauses,public array $recommendations,public array $roadmap,public array $evidence,public array $unknowns,public array $contradictions){}
    public function jsonSerialize():array{return get_object_vars($this);}
}
