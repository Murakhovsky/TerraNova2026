<?php
declare(strict_types=1);
namespace App\Application\Capability;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Finance\Application\Service\FinancePaidSettlementStatusService;
use Kernel\Tenant\Model\TenantContext;
final readonly class FinancePaidSettlementStatusAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private FinancePaidSettlementStatusService $service){}
    public function binding():string{return 'query:finance.ad_spend.settlement.status';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {return $this->service->status($actor,$input['reservation_id'],$input['document_ref'],$input['snapshot_ref']);}
}
