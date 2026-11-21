<?php
declare(strict_types=1);

namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Application\ProductManagement\Query\ProductManagementQuery;
use DomainException;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Tenant\Model\TenantContext;

final readonly class ProductImportBatchGetAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private QueryBusInterface $queries) {}
    public function binding():string { return 'query:product_management.import.batch.get'; }
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        foreach (['organization_id','tenant_id','actor_id','approved_by','idempotency_key'] as $field) {
            if (array_key_exists($field,$input)) throw new DomainException('Server-owned identity is forbidden.');
        }
        return $this->queries->ask(new ProductManagementQuery(
            $actor->organizationId(),'import.batch.get',$input
        ));
    }
}
