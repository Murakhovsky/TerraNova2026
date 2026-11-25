<?php
declare(strict_types=1);
namespace App\Infrastructure\Capability;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Application\Sales\Command\ManageSalesOfferCommand;
use DomainException;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Tenant\Model\TenantContext;

final readonly class SalesOfferReviseAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private CommandBusInterface $commands){}
    public function binding():string { return 'command:sales.offer.revise'; }
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        $user=$actor->userId()->value();
        if(!ctype_digit($user)||(int)$user<1||(string)(int)$user!==$user)throw new DomainException('Invalid Sales operator.');
        $result=$this->commands->dispatch(new ManageSalesOfferCommand($actor->organizationId(),(int)$user,'revise',$input,$key,$correlationId));
        if(!is_array($result)||($result['offer']['deal_id']??null)!==($input['deal_id']??null)||!is_bool($result['replayed']??null)||!is_int($result['applied_revision']??null))throw new DomainException('Invalid native offer receipt.');
        return $result;
    }
}
