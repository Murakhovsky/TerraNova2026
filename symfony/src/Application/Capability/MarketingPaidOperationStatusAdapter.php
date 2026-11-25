<?php
declare(strict_types=1);
namespace App\Application\Capability;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingPaidOperationsReadService;
use Kernel\Tenant\Model\TenantContext;
final readonly class MarketingPaidOperationStatusAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingPaidOperationsReadService $read){}
    public function binding():string{return 'query:marketing.paid.operation.status';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed{
        return $this->read->status($actor,$input['plan_id']);
    }
}
