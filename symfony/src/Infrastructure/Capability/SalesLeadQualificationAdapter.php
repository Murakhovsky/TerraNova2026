<?php
declare(strict_types=1);

namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Application\Sales\Command\UpdateSalesLeadCommand;
use App\Application\Sales\Command\SalesMutationResult;
use DomainException;
use Domains\Sales\Application\Contract\SalesProposalLeadReadModelInterface;
use Domains\Sales\Domain\Policy\LeadQualificationPolicy;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Tenant\Model\TenantContext;

final readonly class SalesLeadQualificationAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private CommandBusInterface $commands,private SalesProposalLeadReadModelInterface $leads) {}
    public function binding():string { return 'command:sales.lead.qualify'; }

    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        $user=$actor->userId()->value();
        $id=$input['lead_id']??null;
        $from=$input['expected_status']??null;
        $to=$input['target_status']??null;
        if (count($input)!==3 || !is_int($id) || $id<1 || !is_string($from) || !is_string($to)
            || !LeadQualificationPolicy::allows($from,$to) || !ctype_digit($user) || (int)$user<1
            || (string)(int)$user!==$user || trim($key)==='' || strlen($key)>191) throw new DomainException('Invalid qualification request.');
        $org=$actor->organizationId()->value();
        $before=$this->leads->lead($org,$id);
        if ($before===null || ($before['organization_id']??null)!==$org || !in_array($before['id']??null,[$id,(string)$id],true)) {
            throw new DomainException('Tenant-owned lead not found.');
        }
        $result=$this->commands->dispatch(new UpdateSalesLeadCommand($actor->organizationId(),(int)$user,$id,$correlationId,
            ['expected_status'=>$from,'status'=>$to]));
        if (!$result instanceof SalesMutationResult || !$result->ok || !in_array($result->code,['updated','already_applied'],true)) {
            throw new DomainException('Lead qualification rejected.');
        }
        $row=$this->leads->lead($org,$id);
        if ($row===null || ($row['organization_id']??null)!==$org || !in_array($row['id']??null,[$id,(string)$id],true)
            || ($row['status']??null)!==$to) throw new DomainException('Lead qualification has no matching native state.');
        return ['lead_id'=>$id,'status'=>$to,'replayed'=>$result->code==='already_applied'];
    }
}
