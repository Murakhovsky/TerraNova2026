<?php
declare(strict_types=1);

namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Application\Property\Command\UpdatePropertyCommand;
use DomainException;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Tenant\Model\TenantContext;

final readonly class PropertyAssetUpdateAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private CommandBusInterface $commands) {}

    public function binding(): string { return 'command:property.asset.update'; }

    public function invoke(TenantContext $actor, array $input, string $key, string $correlationId): mixed
    {
        $reference = $input['reference'] ?? null;
        $patch = $input['patch'] ?? null;
        if (!is_string($reference) || trim($reference) === '' || strlen($reference) > 191
            || !is_array($patch) || $patch === [] || array_is_list($patch)) {
            throw new DomainException('Property reference and a nonempty patch are required.');
        }
        foreach (['asset_id', 'organization_id', 'tenant_id', 'actor_id', 'legacy_property_id'] as $reserved) {
            if (array_key_exists($reserved, $patch)) {
                throw new DomainException('Immutable Property identity cannot be changed: ' . $reserved);
            }
        }
        $actorId = $actor->userId()->value();
        if (!ctype_digit($actorId) || (int) $actorId < 1) {
            throw new DomainException('Authenticated human actor ID required.');
        }
        return $this->commands->dispatch(new UpdatePropertyCommand(
            $actor->organizationId(), (int) $actorId, $correlationId, $reference, $key, $patch,
        ));
    }
}
