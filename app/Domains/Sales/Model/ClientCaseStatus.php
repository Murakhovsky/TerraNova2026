<?php
declare(strict_types=1);

namespace Domains\Sales\Model;

enum ClientCaseStatus: string
{
    use HasStringValues;

    case Active = 'active';
    case Paused = 'paused';
    case Closed = 'closed';
    case Lost = 'lost';

    public function isTerminal(): bool
    {
        return $this === self::Closed || $this === self::Lost;
    }
}
