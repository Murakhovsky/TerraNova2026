<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;

use DomainException;

/**
 * Entry boundary for raw exchange text messages.
 * Enforces byte limit, filters controls, then validates venue-specific data.
 */
final readonly class L2FrameDispatch
{
    public function __construct(
        private L2FrameSizeGuard $sizeGuard,
        private L2ControlFrameFilter $controls,
        private L2WebSocketFrameRouter $router,
        private string $venue,
    ) {}

    /** @return array<string,mixed>|null */
    public function decode(string $json): ?array
    {
        $this->sizeGuard->assertAllowed($json);
        $kind = $this->controls->classify($this->venue, $json);
        if ($kind === 'CONTROL') return null;
        if ($kind !== 'DATA') throw new DomainException('Unknown L2 frame classification.');
        return $this->router->decode($json);
    }
}
