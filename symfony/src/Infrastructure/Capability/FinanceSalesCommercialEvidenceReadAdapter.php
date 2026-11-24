<?php
declare(strict_types=1);
namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Sales\Application\Contract\SalesCommercialEvidencePort;
use DomainException;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;

/** Finance reads operator-accepted terms as source evidence, never creates a receivable or settlement. */
final readonly class FinanceSalesCommercialEvidenceReadAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private SalesCommercialEvidencePort $sales,private ActiveModuleResolver $modules){}
    public function binding():string { return 'query:finance.sales.commercial_evidence.read'; }
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        $id=$input['deal_id']??null;$org=$actor->organizationId()->value();
        if(count($input)!==1||!is_int($id)||$id<1||!$this->modules->isEnabled($org,'sales'))throw new DomainException('Sales source unavailable or invalid deal identity.');
        $evidence=$this->sales->acceptedOffer($org,$id);
        if($evidence!==null&&(($evidence['deal_id']??null)!==$id||($evidence['settled']??true)!==false))throw new DomainException('Invalid commercial evidence.');
        return ['found'=>$evidence!==null,'evidence'=>$evidence];
    }
}
