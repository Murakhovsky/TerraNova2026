<?php
declare(strict_types=1);
namespace App\Infrastructure\Capability;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Application\ProductManagement\Query\ProductManagementQuery;
use DomainException;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Tenant\Model\TenantContext;
final readonly class ProductCategorySearchAdapter implements CapabilityInvocationAdapterInterface {
    public function __construct(private QueryBusInterface $queries) {}
    public function binding():string { return 'query:product_management.category.search'; }
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed {
        foreach (['organization_id','tenant_id','actor_id','idempotency_key','approved_by'] as $field) {
            if (array_key_exists($field,$input)) throw new DomainException('Server-owned identity forbidden.');
        }
        return $this->queries->ask(new ProductManagementQuery(
            $actor->organizationId(),'category.search',$input
        ));
    }
}
