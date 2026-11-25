<?php
declare(strict_types=1);
namespace Domains\ProductManagement\Application\Contract;

/** Tenant-bound product/variant observation for negotiated quotes; no stock or price writes. */
interface ProductCommercialReferencePort
{
    public function quoteReference(string $organizationId,string $productId,string $variantId):?array;
}
