<?php
declare(strict_types=1);
namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Application\ProductManagement\Command\ProductMutationCommand;
use DomainException;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Tenant\Model\TenantContext;

final readonly class ProductAttributeLifecycleChangeAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private CommandBusInterface $commands) {}
    public function binding():string { return 'command:product_management.attribute.lifecycle.change'; }
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        foreach (['organization_id','tenant_id','actor_id','idempotency_key','approved_by'] as $field) {
            if (array_key_exists($field,$input)) throw new DomainException('Server-owned identity forbidden.');
        }
        return $this->commands->dispatch(new ProductMutationCommand(
            $actor->organizationId(),'attribute.lifecycle.change',$input,$key,$actor->userId()->value(),$correlationId,
        ));
    }
}
