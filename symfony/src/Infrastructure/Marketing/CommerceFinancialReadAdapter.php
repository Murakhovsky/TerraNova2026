<?php
declare(strict_types=1);
namespace App\Infrastructure\Marketing;
use DomainException;
use Domains\Marketing\Application\Contract\MarketingCommerceFinancialReadPort;
use Domains\Finance\Application\Contract\CommerceOrderFinancialEvidenceReadPort;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;
final readonly class CommerceFinancialReadAdapter implements MarketingCommerceFinancialReadPort
{
    public function __construct(private ActiveModuleResolver $modules,private ?CommerceOrderFinancialEvidenceReadPort $finance=null){}
    public function financial(TenantContext $actor,string $orderId):?array
    {
        $org=$actor->organizationId()->value();
        if(!$this->modules->isEnabled($org,'finance')||$this->finance===null)return null;
        if(!preg_match('/^[a-f0-9]{32}$/D',$orderId))throw new DomainException('Invalid Order identity.');
        return $this->finance->financialEvidence($org,$orderId);
    }
}
