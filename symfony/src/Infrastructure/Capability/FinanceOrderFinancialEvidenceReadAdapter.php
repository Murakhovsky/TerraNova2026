<?php
declare(strict_types=1);
namespace App\Infrastructure\Capability;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Finance\Application\Contract\CommerceOrderFinancialEvidenceReadPort;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use DomainException;
final readonly class FinanceOrderFinancialEvidenceReadAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private CommerceOrderFinancialEvidenceReadPort $finance){}
    public function binding():string{return 'query:finance.order.financial_evidence.read';}
    public function invoke(TenantContext $actor,array $input,string $key,string $trace):mixed
    {
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE))throw new DomainException('Finance tenant manager required.');
        return ['evidence'=>$this->finance->financialEvidence($actor->organizationId()->value(),$input['order_id'])];
    }
}
