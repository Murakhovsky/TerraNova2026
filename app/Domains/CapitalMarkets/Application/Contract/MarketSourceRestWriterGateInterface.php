<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

/**
 * Source-scoped, commit-boundary REST writer admission.
 * The callback must contain ALL source-health and canonical state writes.
 */
interface MarketSourceRestWriterGateInterface
{
    public function executeRest(string $organizationId,string $sourceId,callable $write):mixed;
}
