<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

enum QualificationOutcome: string
{
    case Qualified = 'qualified';
    case Monitor = 'monitor';
    case Disqualified = 'disqualified';
}
