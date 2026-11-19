<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Service;

use DomainException;
use Domains\Marketing\Application\Contract\MarketingPlanningRepository;
use Domains\Marketing\Domain\MarketingPlanningPolicy;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

/** M2 proposals, drafts and independent human review; never writes Meta or Finance. */
final readonly class MarketingPlanningService
{
    public function __construct(private MarketingPlanningRepository $planning,private MarketingPlanningPolicy $policy){}

    /** @param array<string,mixed> $content @return array<string,mixed> */
    public function createCreative(TenantContext $actor,string $campaignId,array $content,string $key):array
    {
        $this->manager($actor);
        return $this->planning->createCreative($actor->organizationId()->value(),
            MarketingPlanningPolicy::id($campaignId,'mkt-'),$actor->userId()->value(),
            $this->policy->creative($content),MarketingPlanningPolicy::key($key));
    }
    /** @param array<string,mixed> $content @return array<string,mixed> */
    public function reviseCreative(TenantContext $actor,string $creativeId,int $revision,array $content,string $key):array
    {
        $this->manager($actor);
        return $this->planning->reviseCreative($actor->organizationId()->value(),
            MarketingPlanningPolicy::id($creativeId,'mkcr-'),$actor->userId()->value(),
            MarketingPlanningPolicy::revision($revision),$this->policy->creative($content),
            MarketingPlanningPolicy::key($key));
    }
    /** @return list<array<string,mixed>> */
    public function creatives(TenantContext $actor,string $campaignId):array
    {
        $this->manager($actor);
        return $this->planning->creatives($actor->organizationId()->value(),
            MarketingPlanningPolicy::id($campaignId,'mkt-'));
    }
    /** @param array<string,mixed> $content @return array<string,mixed> */
    public function submitProposal(TenantContext $actor,string $campaignId,string $creativeId,int $campaignRevision,int $creativeRevision,array $content,string $key):array
    {
        $this->manager($actor);
        return $this->planning->submitProposal($actor->organizationId()->value(),
            MarketingPlanningPolicy::id($campaignId,'mkt-'),
            MarketingPlanningPolicy::id($creativeId,'mkcr-'),
            MarketingPlanningPolicy::revision($campaignRevision),
            MarketingPlanningPolicy::revision($creativeRevision),
            $actor->userId()->value(),$this->policy->proposal($content),
            MarketingPlanningPolicy::key($key));
    }
    /** @return list<array<string,mixed>> */
    public function proposals(TenantContext $actor,string $campaignId):array
    {
        $this->manager($actor);
        return $this->planning->proposals($actor->organizationId()->value(),
            MarketingPlanningPolicy::id($campaignId,'mkt-'));
    }
    /** @param array<string,mixed> $review @return array<string,mixed> */
    public function reviewProposal(TenantContext $actor,string $id,int $expectedRevision,array $review,string $key):array
    {
        $this->manager($actor);
        return $this->planning->reviewProposal($actor->organizationId()->value(),
            MarketingPlanningPolicy::id($id,'mkpr-'),$actor->userId()->value(),
            MarketingPlanningPolicy::revision($expectedRevision),
            $this->policy->review($review),MarketingPlanningPolicy::key($key));
    }
    private function manager(TenantContext $actor):void
    {
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)){
            throw new DomainException('Marketing manager required for planning and human review.');
        }
    }
}

