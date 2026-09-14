<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Model;

enum DiagnosticSessionStatus: string
{
    use HasStringValues;

    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }
}
