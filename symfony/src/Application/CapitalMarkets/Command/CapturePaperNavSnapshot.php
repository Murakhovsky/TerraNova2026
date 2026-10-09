<?php
declare(strict_types=1);

namespace App\Application\CapitalMarkets\Command;

/** A scheduler message with an explicit existing tenant; never provisions capital. */
final readonly class CapturePaperNavSnapshot
{
    public function __construct(
        public string $organizationId,
        public string $portfolioId='paper-master',
    ) {}
}
