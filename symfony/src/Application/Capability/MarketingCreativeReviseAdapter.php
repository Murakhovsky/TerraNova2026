<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingPlanningService;
use Kernel\Tenant\Model\TenantContext;

/** Delegates only to the Marketing planning owner; never to provider writes. */
final readonly class MarketingCreativeReviseAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingPlanningService $planning){}
    public function binding():string{return 'command:marketing.creative.revise';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        $creative=(string)$input['creative_id'];$revision=(int)$input['expected_revision'];
        unset($input['creative_id'],$input['expected_revision']);
        return $this->planning->reviseCreative($actor,$creative,$revision,$input,$key);
    }
}

