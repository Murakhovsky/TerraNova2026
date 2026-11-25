<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingPlanningService;
use Kernel\Tenant\Model\TenantContext;

/** Delegates only to the Marketing planning owner; never to provider writes. */
final readonly class MarketingAnalystProposalReviewAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingPlanningService $planning){}
    public function binding():string{return 'command:marketing.analyst.proposal.review';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        $id=(string)$input['proposal_id'];$revision=(int)$input['expected_revision'];
        unset($input['proposal_id'],$input['expected_revision']);
        return $this->planning->reviewProposal($actor,$id,$revision,$input,$key);
    }
}

