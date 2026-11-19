<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingPlanningService;
use Kernel\Tenant\Model\TenantContext;

/** Delegates only to the Marketing planning owner; never to provider writes. */
final readonly class MarketingCreativeListAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingPlanningService $planning){}
    public function binding():string{return 'query:marketing.creative.list';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        return ['items'=>$this->planning->creatives($actor,(string)$input['campaign_id'])];
    }
}

