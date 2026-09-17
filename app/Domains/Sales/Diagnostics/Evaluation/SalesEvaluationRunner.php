<?php
declare(strict_types=1);

namespace Domains\Sales\Diagnostics\Evaluation;

use Domains\Diagnostic\Evaluation\EvaluationDatasetRunner;
use Domains\Diagnostic\Methodology\CompiledDiagnosticPack;

/** Sales-owned benchmark entrypoint backed by the generic deterministic Diagnostic runner. */
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
