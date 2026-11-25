<?php
declare(strict_types=1);

namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use DomainException;
use Domains\Sales\Application\Contract\CrmInboxRecoveryRepositoryInterface;
use Kernel\Tenant\Model\TenantContext;

final readonly class SalesCrmInboxStatusAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private CrmInboxRecoveryRepositoryInterface $inbox) {}
    public function binding(): string { return 'query:sales.crm.inbox.status'; }

    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        $id=$input['inbox_id']??null;
        if (count($input)!==1 || !is_string($id) || preg_match('/^[a-f0-9]{32}$/D',$id)!==1) throw new DomainException('Invalid CRM inbox identity.');
        $org=$actor->organizationId()->value();
        $row=$this->inbox->status($org,$id);
        if ($row===null) return ['found'=>false,'item'=>null];
        if (($row['organization_id']??null)!==$org || ($row['id']??null)!==$id
            || !in_array($row['status']??null,['RECEIVED','PROCESSING','COMPLETED','FAILED','DEAD','IGNORED'],true)
            || !is_int($row['attempts']??null) || $row['attempts']<0
            || !is_bool($row['signature_verified']??null)
            || preg_match('/^[a-f0-9]{64}$/D',(string)($row['revision']??''))!==1) throw new DomainException('Invalid native CRM observation.');
        return ['found'=>true,'item'=>[
            'inbox_id'=>$id,'status'=>$row['status'],'attempts'=>$row['attempts'],
            'signature_verified'=>$row['signature_verified'],'revision'=>$row['revision'],
            'provider'=>(string)$row['provider'],'event_type'=>(string)$row['event_type'],
        ]];
    }
}
