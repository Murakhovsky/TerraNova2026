<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Evaluation;

use Domains\Diagnostic\Methodology\CompiledDiagnosticPack;
use Domains\Sales\Diagnostics\Evaluation\SalesEvaluationRunner as CanonicalSalesEvaluationRunner;

/** @deprecated Sales-specific evaluation belongs to Domains\Sales\Diagnostics. */
final readonly class SalesEvaluationRunner
{
    public function __construct(private CanonicalSalesEvaluationRunner $runner = new CanonicalSalesEvaluationRunner())
    {
    }

    /** @param array<string,mixed> $dataset @return array<string,float|int> */
    public function run(CompiledDiagnosticPack $pack, array $dataset): array
    {
        return $this->runner->run($pack, $dataset);
    }
}
