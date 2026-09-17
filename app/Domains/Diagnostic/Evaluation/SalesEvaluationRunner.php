<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Evaluation;

use Domains\Diagnostic\Methodology\CompiledDiagnosticPack;

/**
 * @deprecated Sales-specific entrypoints belong to Domains\Sales\Diagnostics.
 * This compatibility facade stays inside Diagnostic without importing Sales.
 */
final readonly class SalesEvaluationRunner
{
    public function __construct(private EvaluationDatasetRunner $runner = new EvaluationDatasetRunner())
    {
    }

    /** @param array<string,mixed> $dataset @return array<string,float|int> */
    public function run(CompiledDiagnosticPack $pack, array $dataset): array
    {
        return $this->runner->run($pack, $dataset);
    }
}
