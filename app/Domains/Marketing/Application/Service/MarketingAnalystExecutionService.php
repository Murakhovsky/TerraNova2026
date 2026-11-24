<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Service;

use DomainException;
use Domains\Marketing\Application\Contract\MarketingAnalystRunStore;
use Domains\Marketing\Application\Contract\MarketingAnalystRuntimePort;
use Domains\Marketing\Domain\MarketingAnalystAdmissionPolicy;
use Domains\Marketing\Domain\MarketingAnalystRecommendationPolicy;
use Domains\Marketing\Domain\MarketingPlanningPolicy;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Throwable;

/** Human-requested advice only. No scheduler, paid Action, Finance write or independent workflow engine. */
final readonly class MarketingAnalystExecutionService
{
    public function __construct(private MarketingAnalystContextService $contexts,private MarketingAnalystRuntimePort $runtime,
        private MarketingAnalystRunStore $runs,private MarketingAnalystAdmissionPolicy $admission,
        private MarketingAnalystRecommendationPolicy $recommendations,private ActiveModuleResolver $modules){}

    /** @return array<string,mixed> */
    public function request(TenantContext $actor,string $campaignId,int $campaignRevision,string $creativeId,int $creativeRevision,
        ?string $adsetId,?int $adsetRevision,string $key,?string $approvedContextHash=null):array
    {
        $org=$actor->organizationId()->value();
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)
            ||!$this->modules->isEnabled($org,'marketing'))throw new DomainException('Marketing manager and active module required.');
        $this->admission->assertAllowed($org);
        MarketingPlanningPolicy::key($key);
        MarketingPlanningPolicy::id($campaignId,'mkt-');MarketingPlanningPolicy::revision($campaignRevision);
        MarketingPlanningPolicy::id($creativeId,'mkcr-');MarketingPlanningPolicy::revision($creativeRevision);
        if(($adsetId===null)!==($adsetRevision===null))throw new DomainException('Incomplete AdSet pin.');
        if($adsetId!==null){MarketingPlanningPolicy::id($adsetId,'mkas-');MarketingPlanningPolicy::revision($adsetRevision);}
        if($approvedContextHash!==null&&!preg_match('/^[a-f0-9]{64}$/D',$approvedContextHash))throw new DomainException('Invalid approved context hash.');
        $actorId=$actor->userId()->value();
        $hash=hash('sha256',json_encode([$actorId,$campaignId,$campaignRevision,$creativeId,$creativeRevision,
            $adsetId,$adsetRevision,$this->admission->profileRef,$approvedContextHash],JSON_THROW_ON_ERROR));
        $existing=$this->runs->byKey($org,$key);
        if($existing!==null){
            if(!hash_equals($existing['request_hash'],$hash))throw new DomainException('Analyst idempotency conflict.');
            return $this->publicReceipt($existing);
        }
        $envelope=$this->contexts->build($actor,$campaignId,$campaignRevision,$creativeId,$creativeRevision,$adsetId,$adsetRevision);
        if($approvedContextHash!==null&&!hash_equals($approvedContextHash,$envelope['context_hash']))throw new DomainException('Approved analyst context changed.');
        $claim=$this->runs->claim($org,$key,[
            'request_hash'=>$hash,'requested_by'=>$actorId,'profile_ref'=>$this->admission->profileRef,
            'cost_control_ref'=>$this->admission->costControlRef,'campaign_id'=>$campaignId,
            'context_hash'=>$envelope['context_hash'],'source_pins'=>$envelope['context']['sources']
        ],$this->admission->dailyRunLimit);
        $run=$claim['run'];
        if(!$claim['claimed'])return $this->publicReceipt($run);
        try{
            // Unique durable receipt is the stable remote correlation. No automatic retry.
            $response=$this->runtime->recommend($actor,$envelope,$run['id']);
            $advice=$this->validate($envelope,$response);
            $fresh=$this->contexts->build($actor,$campaignId,$campaignRevision,$creativeId,$creativeRevision,$adsetId,$adsetRevision);
            if(!hash_equals($envelope['context_hash'],$fresh['context_hash']))throw new DomainException('Analyst context changed.');
        }catch(Throwable){
            // A timeout, malformed response or stale data can still have incurred external cost.
            // Keep the consumed slot; never resubmit under the same key.
            return $this->publicReceipt($this->runs->finish($org,$run['id'],null));
        }
        // Database failure here deliberately leaves STARTED or the already committed result.
        return $this->publicReceipt($this->runs->finish($org,$run['id'],$advice));
    }

    /** @return array<string,mixed> */
    private function validate(array $envelope,array $response):array
    {
        $id=$response['runtime_response_id']??null;$evidence=$response['evidence']??null;
        if(($response['runtime']??null)!=='HERMES_AGENT'||($response['status']??null)!=='UNREVIEWED_ADVICE'
            ||($response['paid_activation_approved']??null)!==false||($response['provider_evidence_verified']??null)!==false
            ||!is_string($id)||!preg_match('/^[A-Za-z0-9_.:-]{1,191}$/D',$id)
            ||!is_array($evidence)||!array_is_list($evidence))throw new DomainException('Untrusted advisory runtime response.');
        $aliases=[];
        foreach($evidence as $item){
            if(!is_array($item)||!is_string($item['alias']??null))throw new DomainException('Invalid runtime evidence.');
            $aliases[]=$item['alias'];
        }
        // Reconstruct evidence from native context, never persist runtime-supplied record IDs.
        return [...$this->recommendations->validate($envelope,[
            'context_hash'=>$response['context_hash']??null,'recommendation'=>$response['recommendation']??null,
            'rationale'=>$response['rationale']??null,'evidence_aliases'=>$aliases
        ]),'runtime'=>'HERMES_AGENT','runtime_response_id'=>$id];
    }

    private function publicReceipt(array $run):array
    {
        unset($run['request_hash'],$run['cost_control_ref']);
        return $run;
    }
}
