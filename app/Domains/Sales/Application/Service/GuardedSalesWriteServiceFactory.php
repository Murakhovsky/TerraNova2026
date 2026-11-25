<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Service;

use Domains\Sales\Application\Contract\SalesWriteServiceFactoryInterface;
use Domains\Sales\Application\Contract\SalesWriteServiceInterface;
use Domains\Sales\Application\Contract\SalesMutationAuthorityInterface;

/** Public authenticated write facade; native factory is the internal implementation. */
final readonly class GuardedSalesWriteServiceFactory implements SalesWriteServiceFactoryInterface
{
    public function __construct(private SalesWriteServiceFactoryInterface $native,
        private SalesMutationGuard $guard,private SalesMutationAuthorityInterface $authority) {}

    public function forOrganization(string $organizationId):SalesWriteServiceInterface
    {
        return new GuardedSalesWriteService($organizationId,$this->native->forOrganization($organizationId),$this->guard,$this->authority);
    }
}
