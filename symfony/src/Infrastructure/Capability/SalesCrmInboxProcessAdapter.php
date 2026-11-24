<?php
declare(strict_types=1);

namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Application\Integration\Command\ProcessCrmInboxNowCommand;
use DomainException;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Tenant\Model\TenantContext;

final readonly class SalesCrmInboxProcessAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private CommandBusInterface $commands) {}
    public function binding():string { return 'command:sales.crm.inbox.process'; }

    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        $user=$actor->userId()->value();
        if (!ctype_digit($user) || (int)$user<1 || (string)(int)$user!==$user || count($input)!==2
            || !is_string($input['inbox_id']??null) || !is_string($input['revision']??null)) throw new DomainException('Invalid CRM processing authority.');
        $result=$this->commands->dispatch(new ProcessCrmInboxNowCommand($actor->organizationId(),(int)$user,
            $input['inbox_id'],$input['revision'],$key,$correlationId));
        if (!is_array($result) || ($result['inbox_id']??null)!==$input['inbox_id']
            || ($result['status']??null)!=='COMPLETED' || !is_bool($result['replayed']??null)) throw new DomainException('CRM processing has no verified completion.');
        return ['inbox_id'=>$result['inbox_id'],'status'=>'COMPLETED','replayed'=>$result['replayed']];
    }
}
