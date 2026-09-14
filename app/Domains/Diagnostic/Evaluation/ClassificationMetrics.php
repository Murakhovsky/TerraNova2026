<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Evaluation;
final readonly class ClassificationMetrics { public function __construct(public float $precision,public float $recall,public float $falsePositiveRate,public float $accuracy,public float $hallucinationRate){} }
