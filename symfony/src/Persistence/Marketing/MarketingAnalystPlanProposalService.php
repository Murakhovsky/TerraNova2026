<?php
declare(strict_types=1);
namespace App\Persistence\Marketing;

use App\Persistence\Federation\FederationGoalStore;
use App\Persistence\Federation\FederationCanonicalWorkflowBridge;
use App\Infrastructure\Marketing\HermesAnalystReadinessService;
use Doctrine\DBAL\Connection;
use DomainException;
use Domains\Marketing\Application\Service\MarketingAnalystContextService;
use Domains\Marketing\Domain\MarketingPlanningPolicy;
use Kernel\Tenant\Model\TenantContext;
use Platform\Orchestration\Goal\GoalSpecification;

/** Creates an immutable one-step canonical Plan only. No approval, enqueue or runtime call. */
final readonly class MarketingAnalystPlanProposalService
{
    public const CAPABILITY='marketing.analyst.run.request';
    public const CRITERION='marketing.analyst.advice_recorded';

    public function __construct(private Connection $db,private FederationGoalStore $goals,
        private FederationCanonicalWorkflowBridge $workflow,private MarketingAnalystContextService $contexts,
        private HermesAnalystReadinessService $readiness){}

    /** @return array<string,mixed> */
    public function propose(TenantContext $actor,string $campaignId,int $campaignRevision,string $creativeId,int $creativeRevision,
        ?string $adsetId,?int $adsetRevision,string $key,?string $paidPlanId=null,?string $snapshotRef=null):array
    {
        MarketingPlanningPolicy::key($key);
        $status=$this->readiness->status($actor);
        if(!$status['plan_available']||!$this->workflow->newRunsEnabled($actor))throw new DomainException('Canonical Marketing analysis planning is not enabled.');
        $envelope=$this->contexts->build($actor,$campaignId,$campaignRevision,$creativeId,$creativeRevision,$adsetId,$adsetRevision,$paidPlanId,$snapshotRef);
        $org=$actor->organizationId()->value();
        $suffix=substr(hash('sha256',json_encode([$org,$actor->userId()->value(),$key],JSON_THROW_ON_ERROR)),0,24);
        $goalId='goal-'.$suffix;$planId='plan-'.$suffix;
        $parameters=['campaign_revision'=>$campaignRevision,'creative_id'=>$creativeId,'creative_revision'=>$creativeRevision,
            'configuration_hash'=>$status['configuration_hash'],'context_hash'=>$envelope['context_hash']];
        if($adsetId!==null){$parameters['adset_id']=$adsetId;$parameters['adset_revision']=$adsetRevision;}
        if($paidPlanId!==null){$parameters['paid_plan_id']=$paidPlanId;$parameters['snapshot_ref']=$snapshotRef;}
        $input=['target_type'=>'marketing_campaign','target_id'=>$campaignId,'parameters'=>$parameters];
        return $this->db->transactional(function()use($actor,$org,$goalId,$planId,$input,$campaignId):array{
            $existing=$this->db->fetchOne('SELECT plan_json FROM cos_federation_plans
                WHERE organization_id=:org AND plan_id=:plan AND goal_id=:goal FOR UPDATE',
                ['org'=>$org,'plan'=>$planId,'goal'=>$goalId]);
            if(is_string($existing)){
                $snapshot=json_decode($existing,true,32,JSON_THROW_ON_ERROR);
                $old=$snapshot['steps'][0]['input']??null;
                if(!is_array($old)||count($snapshot['steps'])!==1||($snapshot['steps'][0]['capability_id']??null)!==self::CAPABILITY
                    ||($old['target_type']??null)!==$input['target_type']||($old['target_id']??null)!==$input['target_id']
                    ||!is_array($old['parameters']??null)||count($old['parameters'])!==count($input['parameters'])){
                    throw new DomainException('Marketing analysis Plan key conflict.');
                }
                foreach($input['parameters'] as $field=>$value){
                    if(($old['parameters'][$field]??null)!==$value)throw new DomainException('Marketing analysis Plan key conflict.');
                }
                return ['goal_id'=>$goalId,'plan_id'=>$planId,'state'=>'existing'];
            }
            $goal=new GoalSpecification($goalId,$org,$actor->userId()->value(),
                'Record one validated Hermes planning recommendation for '.$campaignId,
                [['id'=>self::CRITERION,'operator'=>'at_least','expected'=>1]],[self::CAPABILITY],1,null,null,'',
                [],['independent_plan','independent_action'],'always');
            $this->goals->createGoal($actor,$goal);
            $this->goals->proposePlan($actor,$planId,$goalId,[['id'=>'marketing-analyst-run',
                'capability_id'=>self::CAPABILITY,'capability_version'=>'1.0.0','input'=>$input]]);
            return ['goal_id'=>$goalId,'plan_id'=>$planId,'state'=>'proposed'];
        });
    }
}
