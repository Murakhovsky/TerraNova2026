<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Interview;
final readonly class FactExtractionResult { public function __construct(public array $candidateFacts,public array $candidateEvidence,public array $metricInputs,public array $uncertainties,public array $contradictions,public array $missingInformation){} }
