<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;
use DomainException;

/** Per-venue bounded wire-frame guard, before JSON decoding. */
final readonly class L2FrameSizeGuard
{
    public function __construct(private int $maxBytes = 2097152)
    {
        if ($maxBytes < 1024 || $maxBytes > 16777216) throw new DomainException('Invalid L2 frame limit.');
    }

    public function assertAllowed(string $frame): void
    {
        if ($frame === '' || strlen($frame) > $this->maxBytes) throw new DomainException('L2 frame size limit exceeded.');
    }
}
