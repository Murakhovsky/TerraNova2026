<?php
declare(strict_types=1);

namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Application\ProductManagement\Query\ProductManagementQuery;
use DomainException;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Tenant\Model\TenantContext;

final readonly class ProductMediaSearchAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private QueryBusInterface $queries) {}
    public function binding():string { return 'query:product_management.media.search'; }
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        foreach(['organization_id','tenant_id','actor_id','idempotency_key','storage_ref','approved_by'] as $field){
            if(array_key_exists($field,$input))throw new DomainException('Server-owned Product Media identity forbidden.');
        }
        return $this->queries->ask(new ProductManagementQuery(
            $actor->organizationId(),'media.search',$input
        ));
    }
}
