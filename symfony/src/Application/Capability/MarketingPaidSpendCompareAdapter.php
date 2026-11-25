<?php
declare(strict_types=1);
namespace App\Application\Capability;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingPaidOperationsReadService;
use Kernel\Tenant\Model\TenantContext;
final readonly class MarketingPaidSpendCompareAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingPaidOperationsReadService $read){}
    public function binding():string{return 'query:marketing.paid.spend.compare';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed{
        return $this->read->compareSpend($actor,$input['plan_id'],$input['snapshot_id']);
    }
}
