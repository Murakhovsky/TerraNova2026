<?php
declare(strict_types=1);
namespace App\Infrastructure\Capability;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Application\Property\Query\SearchPropertiesQuery;
use DomainException;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Tenant\Model\TenantContext;
final readonly class PropertyAssetSearchAdapter implements CapabilityInvocationAdapterInterface {
    public function __construct(private QueryBusInterface $queries) {}
    public function binding():string { return 'query:property.asset.search'; }
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed {
        if (array_key_exists('tenant_id',$input)||array_key_exists('organization_id',$input)||count($input)>30) {
            throw new DomainException('Unsafe search scope.');
        }
        return $this->queries->ask(new SearchPropertiesQuery($actor->organizationId(),$input));
    }
}
