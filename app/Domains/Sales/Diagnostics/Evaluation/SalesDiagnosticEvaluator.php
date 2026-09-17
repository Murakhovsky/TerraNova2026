<?php
declare(strict_types=1);

namespace Domains\Sales\Diagnostics\Evaluation;

use Domains\Diagnostic\Methodology\Engine\MethodologyEngine;
use Domains\Diagnostic\Methodology\Input\DiagnosticInput;
use Domains\Sales\Diagnostics\Methodology\SalesDiagnosticDefinition;

final readonly class SalesDiagnosticEvaluator
{
    public function __construct(private MethodologyEngine $engine = new MethodologyEngine())
    {
    }

    public function evaluate(DiagnosticInput $input, SalesDiagnosticDefinition $definition): SalesDiagnosticResult
    {
        return new SalesDiagnosticResult($this->engine->evaluate($input, $definition->pack));
    }
}
