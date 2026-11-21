<?php
declare(strict_types=1);

namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Application\ProductManagement\Command\CreateProductCommand;
use DomainException;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Tenant\Model\TenantContext;

final readonly class ProductCreateAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private CommandBusInterface $commands) {}
    public function binding():string { return 'command:product_management.product.create'; }

    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        foreach (['tenant_id','organization_id','actor_id','product_id','variant_id'] as $field) {
            if (array_key_exists($field,$input)) throw new DomainException('Server-owned identity cannot be supplied.');
        }
        return $this->commands->dispatch(new CreateProductCommand(
            $actor->organizationId(),$input,$key,$actor->userId()->value(),$correlationId
        ));
    }
}
