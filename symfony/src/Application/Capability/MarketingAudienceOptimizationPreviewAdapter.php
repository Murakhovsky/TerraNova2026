<?php
declare(strict_types=1);
namespace App\Application\Capability;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingAudienceOptimizationService;
use Kernel\Tenant\Model\TenantContext;
final readonly class MarketingAudienceOptimizationPreviewAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingAudienceOptimizationService $service){}
    public function binding():string{return 'query:marketing.optimization.audience.preview';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {return $this->service->preview($actor,$input['first_plan_id'],$input['first_snapshot_ref'],$input['second_plan_id'],$input['second_snapshot_ref']);}
}
