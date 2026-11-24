<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Service;

use DomainException;
use Domains\Marketing\Application\Contract\MarketingAnalystRunStore;
use Domains\Marketing\Domain\MarketingPlanningPolicy;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

/** Independent human decisions on advice, never an Action or approval for paid execution. */
final readonly class MarketingAnalystReviewService
{
    public function __construct(private MarketingAnalystRunStore $runs,private MarketingAnalystContextService $contexts,
        private MarketingCampaignService $campaigns,private MarketingPlanningPolicy $policy,private ActiveModuleResolver $modules){}

    /** @return list<array<string,mixed>> */
    public function listRuns(TenantContext $actor,string $campaignId,int $limit=50,int $offset=0):array
    {
        $org=$this->guard($actor,$campaignId);
        return array_map(self::project(...),$this->runs->listRuns($org,$campaignId,$limit,$offset));
    }

    /** @return array<string,mixed> */
    public function review(TenantContext $actor,string $campaignId,string $id,string $decision,string $reason,string $key):array
    {
        $org=$this->guard($actor,$campaignId);
        $decision=$this->policy->review(['decision'=>$decision,'reason'=>$reason]);
        return self::project($this->runs->decide($org,MarketingPlanningPolicy::id($id,'mkar-'),$campaignId,
            $actor->userId()->value(),MarketingPlanningPolicy::key($key),$decision,function(array $run)use($actor):void{
                $pins=array_column($run['source_pins'],null,'alias');
                $fresh=$this->contexts->build($actor,$pins['campaign']['record_id'],$pins['campaign']['revision'],
                    $pins['creative']['record_id'],$pins['creative']['revision'],$pins['adset']['record_id']??null,$pins['adset']['revision']??null);
                if(!hash_equals($run['context_hash'],$fresh['context_hash']))throw new DomainException('Advice context is stale.');
            }));
    }

    /** @return array<string,mixed> */
    public function recover(TenantContext $actor,string $campaignId,string $id,string $reason,string $evidenceRef,
        bool $localAndRemoteStopped,string $key):array
    {
        $org=$this->guard($actor,$campaignId);
        if(!$localAndRemoteStopped||!preg_match('/^[A-Za-z0-9_.:-]{8,190}$/D',$evidenceRef)
            ||mb_strlen(trim($reason))<10||mb_strlen(trim($reason))>300){
            throw new DomainException('Recovery requires operator verification of local and remote termination, an evidence reference and reason.');
        }
        return self::project($this->runs->decide($org,MarketingPlanningPolicy::id($id,'mkar-'),$campaignId,
            $actor->userId()->value(),MarketingPlanningPolicy::key($key),[
                'decision'=>'RECOVERED_UNCERTAIN','reason'=>trim($reason),'evidence_ref'=>$evidenceRef
            ],static function(array $run):void{throw new DomainException('Recovery is not approval.');}));
    }

    private function guard(TenantContext $actor,string $campaignId):string
    {
        $org=$actor->organizationId()->value();
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)
            ||!$this->modules->isEnabled($org,'marketing')||$this->campaigns->get($actor,$campaignId)===null){
            throw new DomainException('Marketing manager, active module and tenant Campaign required.');
        }
        return $org;
    }

    /** @param array<string,mixed> $run @return array<string,mixed> */
    private static function project(array $run):array
    {
        unset($run['request_hash'],$run['cost_control_ref'],$run['profile_ref']);
        return $run;
    }
}
