<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Contract;

use Kernel\Tenant\Model\TenantContext;

/** Read-only Finance owner observation; not an atomic spending claim or Action authorization. */
interface MarketingFinanceFundingReadPort
{
    /** @return array{envelope:array<string,mixed>,reservation:array<string,mixed>}|null */
    public function observe(TenantContext $actor,string $envelopeId,string $reservationId):?array;
}
