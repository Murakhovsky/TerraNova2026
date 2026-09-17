<?php
declare(strict_types=1);

namespace Platform\Integration\Model;

enum ConnectionStatus: string
{
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case DEGRADED = 'degraded';
    case REVOKED = 'revoked';
    case DISABLED = 'disabled';

    public function usable(): bool
    {
        return $this === self::ACTIVE || $this === self::DEGRADED;
    }
}
