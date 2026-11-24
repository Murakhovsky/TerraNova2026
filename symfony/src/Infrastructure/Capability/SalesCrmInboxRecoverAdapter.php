<?php
declare(strict_types=1);

namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Application\Integration\Command\RecoverCrmInboxCommand;
use App\Application\Integration\Command\RecoverCrmInboxCommandHandler;
use DomainException;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Tenant\Model\TenantContext;

final readonly class SalesCrmInboxRecoverAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private CommandBusInterface $commands) {}
    public function binding():string { return 'command:sales.crm.inbox.recover'; }

    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        $user=$actor->userId()->value();
        if (!ctype_digit($user) || (int)$user<1 || (string)(int)$user!==$user
            || count($input)!==3 || !is_string($input['inbox_id']??null) || !is_string($input['revision']??null)
            || !in_array($input['reason']??null,RecoverCrmInboxCommandHandler::REASONS,true)) throw new DomainException('Invalid CRM recovery authority or input.');
        $result=$this->commands->dispatch(new RecoverCrmInboxCommand($actor->organizationId(),(int)$user,
            $input['inbox_id'],$input['revision'],$input['reason'],$key,$correlationId));
        if (!is_array($result) || ($result['inbox_id']??null)!==$input['inbox_id']
            || ($result['requeued']??null)!==true || !is_bool($result['replayed']??null)) throw new DomainException('CRM recovery has no verified receipt.');
        return ['inbox_id'=>$result['inbox_id'],'requeued'=>true,'replayed'=>$result['replayed']];
    }
}
