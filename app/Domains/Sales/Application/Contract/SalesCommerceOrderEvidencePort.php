<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;

/** Trusted cross-domain placement fact. No campaign assignment or financial assertion. */
interface SalesCommerceOrderEvidencePort
{
    public function placement(string $organizationId,string $orderId):?array;
}
