<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Contract;

/**
 * Read-only, tenant-bound Product snapshot. Marketing must never load Product SQL
 * tables or claim inventory/price facts that the Product owner did not return.
 */
interface MarketingProductDraftReadPort
{
    /** @return array<string,mixed>|null */
    public function inspect(string $organizationId,string $productId,?string $variantId,string $currency):?array;
}
