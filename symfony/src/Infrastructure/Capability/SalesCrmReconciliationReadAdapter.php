<?php
declare(strict_types=1);
namespace App\Infrastructure\Capability;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Sales\Application\Contract\SalesCrmReconciliationReadPort;
use Kernel\Tenant\Model\TenantContext;
use DomainException;
final readonly class SalesCrmReconciliationReadAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private SalesCrmReconciliationReadPort $crm){}
    public function binding():string{return 'query:sales.crm.reconciliation.read';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        $deal=$input['deal_id']??null;$provider=$input['provider']??null;$kind=$input['kind']??null;$operation=$input['operation_key']??null;
        if(count($input)!==4||!is_int($deal)||$deal<1||!is_string($provider)||preg_match('/^[a-z][a-z0-9_-]{0,39}$/D',$provider)!==1||!in_array($kind,['task','followup','message'],true)||!is_string($operation)||trim($operation)===''||strlen($operation)>191)throw new DomainException('Invalid CRM reconciliation reference.');
        return $this->crm->reconcile($actor->organizationId()->value(),$deal,$provider,$kind,$operation);
    }
}
