<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingPlanningService;
use Kernel\Tenant\Model\TenantContext;

/** Delegates only to the Marketing planning owner; never to provider writes. */
final readonly class MarketingCreativeCreateAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingPlanningService $planning){}
    public function binding():string{return 'command:marketing.creative.create';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        $campaign=(string)$input['campaign_id'];
        unset($input['campaign_id']);
        return $this->planning->createCreative($actor,$campaign,$input,$key);
    }
}

