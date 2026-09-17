<?php
declare(strict_types=1);

namespace Domains\Sales\Diagnostics\Evaluation;

use Domains\Diagnostic\Methodology\Result\DiagnosticResult;

final readonly class SalesDiagnosticResult
{
    public function __construct(public DiagnosticResult $result)
    {
    }

    public function score(): ?float
    {
        return $this->result->score;
    }

    public function coverage(): float
    {
        return $this->result->coverage->ratio;
    }

    public function confidence(): float
    {
        return $this->result->confidence;
    }
}
