<?php
declare(strict_types=1);
namespace App\Application\Sales;

use Domains\Sales\Application\Contract\SalesOfferReferencePort;
use Domains\ProductManagement\Application\Contract\ProductCommercialReferencePort;
use Domains\Property\Contract\PropertyReferencePort;
use Kernel\Module\ActiveModuleResolver;

/** Compatibility composition wrapper; business reference policy belongs to Sales. */
final readonly class SalesOfferReferenceResolver implements SalesOfferReferencePort
{
    private \Domains\Sales\Application\Service\SalesOfferReferenceResolver $native;
    public function __construct(ProductCommercialReferencePort $products,PropertyReferencePort $properties,ActiveModuleResolver $modules)
    {
        $this->native=new \Domains\Sales\Application\Service\SalesOfferReferenceResolver($products,$properties,$modules);
    }
    public function resolve(string $org,array $line):?array { return $this->native->resolve($org,$line); }
}
