<?php
declare(strict_types=1);
namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Application\ProductManagement\Command\ProductMutationCommand;
use DomainException;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Tenant\Model\TenantContext;

/** Tenant manager only; external Sales/OMS automation awaits a separate ADR. */
final readonly class ProductStockReserveAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private CommandBusInterface $commands){}
    public function binding():string{return 'command:product_management.stock.reserve';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        foreach(['organization_id','tenant_id','actor_id','idempotency_key','approved_by','order_id'] as $field){
            if(array_key_exists($field,$input))throw new DomainException('Server-owned stock identity forbidden.');
        }
        return $this->commands->dispatch(new ProductMutationCommand(
            $actor->organizationId(),'stock.reserve',$input,$key,$actor->userId()->value(),$correlationId,
        ));
    }
}
