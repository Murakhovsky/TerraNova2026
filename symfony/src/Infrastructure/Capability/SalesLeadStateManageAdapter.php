<?php
declare(strict_types=1);

namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Application\Sales\Command\ManageSalesLeadStateCommand;
use DomainException;
use Domains\Sales\Domain\Policy\LeadLifecyclePolicy;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Tenant\Model\TenantContext;

final readonly class SalesLeadStateManageAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private CommandBusInterface $commands) {}
    public function binding():string { return 'command:sales.lead.state.manage'; }

    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        $id=$input['lead_id']??null;$from=$input['expected_status']??null;$to=$input['target_status']??null;$user=$actor->userId()->value();
        if (count($input)!==3 || !is_int($id) || $id<1 || !is_string($from) || !is_string($to)
            || !LeadLifecyclePolicy::allows($from,$to) || !ctype_digit($user) || (int)$user<1 || (string)(int)$user!==$user) throw new DomainException('Invalid lead state request.');
        $result=$this->commands->dispatch(new ManageSalesLeadStateCommand($actor->organizationId(),(int)$user,$id,$from,$to,$key,$correlationId));
        if (!is_array($result) || ($result['lead_id']??null)!==$id || ($result['status']??null)!==$to || !is_bool($result['replayed']??null)) throw new DomainException('Invalid native lead state receipt.');
        return $result;
    }
}
