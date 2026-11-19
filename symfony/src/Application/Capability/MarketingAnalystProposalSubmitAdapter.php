<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingPlanningService;
use Kernel\Tenant\Model\TenantContext;

/** Delegates only to the Marketing planning owner; never to provider writes. */
final readonly class MarketingAnalystProposalSubmitAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingPlanningService $planning){}
    public function binding():string{return 'command:marketing.analyst.proposal.submit';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        $campaign=(string)$input['campaign_id'];$creative=(string)$input['creative_id'];
        $cRevision=(int)$input['campaign_revision'];$rRevision=(int)$input['creative_revision'];
        unset($input['campaign_id'],$input['creative_id'],$input['campaign_revision'],$input['creative_revision']);
        return $this->planning->submitProposal($actor,$campaign,$creative,$cRevision,$rRevision,$input,$key);
    }
}

