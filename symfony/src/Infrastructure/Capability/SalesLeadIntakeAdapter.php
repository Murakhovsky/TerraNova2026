<?php
declare(strict_types=1);
namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Application\Sales\Command\ReceivePublicLeadCommand;
use App\Application\Sales\Command\SalesMutationResult;
use Domains\Sales\Application\Contract\SalesProposalLeadReadModelInterface;
use DomainException;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Tenant\Model\TenantContext;

/** Operator-entered inbound form, never a way to impersonate signed CRM provider delivery. */
final readonly class SalesLeadIntakeAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private CommandBusInterface $commands,private SalesProposalLeadReadModelInterface $leads){}
    public function binding():string { return 'command:sales.lead.intake'; }
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        $user=$actor->userId()->value();$org=$actor->organizationId()->value();
        if(!ctype_digit($user)||(int)$user<1||(string)(int)$user!==$user||trim($key)===''||strlen($key)>191)throw new DomainException('Invalid intake operator.');
        if(array_diff(array_keys($input),['full_name','phone','email','role','deal_type','message','request_intent','property_id','telegram'])!==[])throw new DomainException('Invalid operator intake fields.');
        $submission=substr(hash('sha256','operator-intake:'.$key),0,32);
        $result=$this->commands->dispatch(new ReceivePublicLeadCommand($actor->organizationId(),[...$input,'submission_id'=>$submission],'operator-intake',(int)$user,$correlationId));
        if(!$result instanceof SalesMutationResult||!$result->ok||!is_int($result->data['lead_id']??null)||!is_bool($result->data['replayed']??null))throw new DomainException('Intake rejected.');
        $id=$result->data['lead_id'];$row=$this->leads->lead($org,$id);
        if($row===null||($row['organization_id']??null)!==$org||(int)$row['id']!==$id||$row['source_page']!=='operator-intake'||(int)$row['client_case_id']<1)throw new DomainException('Intake native lead/case link missing.');
        return ['lead_id'=>$id,'deal_id'=>(int)$row['client_case_id'],'replayed'=>$result->data['replayed']];
    }
}
