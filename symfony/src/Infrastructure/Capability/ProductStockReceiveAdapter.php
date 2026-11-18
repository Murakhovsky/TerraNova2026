<?php
declare(strict_types=1);

namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Application\ProductManagement\Command\ProductMutationCommand;
use DomainException;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Tenant\Model\TenantContext;

final readonly class ProductStockReceiveAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private CommandBusInterface $commands) {}

    public function binding(): string
    {
        return 'command:product_management.stock.receive';
    }

    public function invoke(TenantContext $actor, array $input, string $key, string $correlationId): mixed
    {
        foreach (['organization_id', 'tenant_id', 'actor_id', 'idempotency_key', 'approved_by'] as $reserved) {
            if (array_key_exists($reserved, $input)) {
                throw new DomainException('Product identity, actor, and approval are server-owned.');
            }
        }
        return $this->commands->dispatch(new ProductMutationCommand(
            $actor->organizationId(), 'stock.receive', $input,
            $key, $actor->userId()->value(), $correlationId,
        ));
    }
}
