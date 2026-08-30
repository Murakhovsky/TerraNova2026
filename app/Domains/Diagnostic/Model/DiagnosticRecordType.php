<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Model;

enum DiagnosticRecordType: string
{
    use HasStringValues;

    case Fact = 'fact';
    case Metric = 'metric';
    case Assessment = 'assessment';
    case Finding = 'finding';
    case Hypothesis = 'hypothesis';
    case Recommendation = 'recommendation';

    public function requiresEvidenceOrUpstream(): bool
    {
        return true;
    }
}
