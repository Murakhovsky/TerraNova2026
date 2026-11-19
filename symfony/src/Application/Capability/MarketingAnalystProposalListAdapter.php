<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingPlanningService;
use Kernel\Tenant\Model\TenantContext;

/** Delegates only to the Marketing planning owner; never to provider writes. */
final readonly class MarketingAnalystProposalListAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingPlanningService $planning){}
    public function binding():string{return 'query:marketing.analyst.proposal.list';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        return ['items'=>$this->planning->proposals($actor,(string)$input['campaign_id'])];
    }
}

