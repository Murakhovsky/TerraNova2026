<?php
declare(strict_types=1);

namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Application\Property\Query\GetPropertyQuery;
use DomainException;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Tenant\Model\TenantContext;

final readonly class PropertyAssetGetAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private QueryBusInterface $queries) {}

    public function binding(): string { return 'query:property.asset.get'; }

    public function invoke(TenantContext $actor, array $input, string $key, string $correlationId): mixed
    {
        $reference = $input['reference'] ?? null;
        if (!is_string($reference) || trim($reference) === '' || strlen($reference) > 191) {
            throw new DomainException('A bounded Property reference is required.');
        }
        return $this->queries->ask(new GetPropertyQuery($actor->organizationId(), $reference));
    }
}
