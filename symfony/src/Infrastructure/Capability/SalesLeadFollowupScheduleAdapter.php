<?php
declare(strict_types=1);
namespace App\Infrastructure\Capability;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Application\Sales\Command\ManageScheduledSalesActivityCommand;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Tenant\Model\TenantContext;
use DomainException;
final readonly class SalesLeadFollowupScheduleAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private CommandBusInterface $commands){}
    public function binding():string{return 'command:sales.lead.followup.schedule';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        $user=$actor->userId()->value();
        if(!ctype_digit($user)||(int)$user<1||(string)(int)$user!==$user)throw new DomainException('Invalid Sales operator.');
        $result=$this->commands->dispatch(new ManageScheduledSalesActivityCommand($actor->organizationId(),(int)$user,'lead.schedule',$input,$key,$correlationId));
        if(!is_array($result)||($result['target_id']??null)!==($input['lead_id']??null)||!is_int($result['activity_id']??null)||!is_bool($result['replayed']??null))throw new DomainException('Invalid native activity receipt.');
        return $result;
    }
}
