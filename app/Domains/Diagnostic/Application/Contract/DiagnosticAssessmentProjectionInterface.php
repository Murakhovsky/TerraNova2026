<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Application\Contract;

use Domains\Diagnostic\Methodology\Result\DiagnosticResult;

/** Read-side projection for structured diagnostic assessment data. */
interface DiagnosticAssessmentProjectionInterface
{
    public function replace(string $organizationId, string $sessionId, DiagnosticResult $result): void;
}
