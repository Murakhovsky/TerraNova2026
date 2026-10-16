<?php
declare(strict_types=1);
namespace App\Infrastructure\Capability;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Application\Property\Command\CreatePropertyCommand;
use DomainException;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Tenant\Model\TenantContext;

final readonly class PropertyAssetCreateAdapter implements CapabilityInvocationAdapterInterface {
    public function __construct(private CommandBusInterface $commands) {}
    public function binding():string { return 'command:property.asset.create'; }
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed {
        foreach (['organization_id','tenant_id','asset_id','actor_id','legacy_property_id'] as $field) {
            if (array_key_exists($field,$input)) throw new DomainException('Property identity is server-owned: '.$field);
        }
        foreach (['type_code','city'] as $field) {
            if (!is_string($input[$field]??null)||trim($input[$field])===''||mb_strlen($input[$field])>200) {
                throw new DomainException('Property field required: '.$field);
            }
        }
        $actorId=$actor->userId()->value();
        if (!ctype_digit($actorId)||(int)$actorId<1) throw new DomainException('Human actor ID required.');
        return $this->commands->dispatch(new CreatePropertyCommand($actor->organizationId(),(int)$actorId,$correlationId,$key,$input));
    }
}
