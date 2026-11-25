<?php
declare(strict_types=1);
namespace App\Persistence\Marketing;
use App\Persistence\Federation\FederationCanonicalWorkflowBridge;
use App\Persistence\Federation\FederationGoalStore;
use App\Persistence\Federation\FederationPlanApprovalCoordinator;
use Doctrine\DBAL\Connection;
use DomainException;
use Domains\Marketing\Application\Service\MarketingCampaignService;
use Domains\Marketing\Application\Service\MarketingPaidLaunchPlanService;
use Domains\Marketing\Automation\Action\MarketingAttributionSweepHandler;
use Domains\Marketing\Domain\MarketingAttributionSchedulePolicy;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Orchestration\Goal\GoalSpecification;
use DateTimeImmutable;
use DateTimeZone;

/** Scheduler plans and advances the existing canonical workflow; no direct business execution. */
final readonly class MarketingAttributionScheduleService
{
    public function __construct(private Connection $db,private FederationGoalStore $goals,private FederationCanonicalWorkflowBridge $workflow,
        private FederationPlanApprovalCoordinator $approvals,private MarketingAttributionSweepOutcomeProvider $outcome,
        private MarketingCampaignService $campaigns,private MarketingPaidLaunchPlanService $plans,private ActiveModuleResolver $modules){}
    public function tick(TenantContext $actor,array $profile,?DateTimeImmutable $clock=null):string
    {
        $profile=MarketingAttributionSchedulePolicy::parse(json_encode([$profile],JSON_THROW_ON_ERROR))[0];
        $org=$actor->organizationId()->value();$who=$actor->userId()->value();
        if($profile['organization_id']!==$org||$profile['actor_id']!==$who||!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)
            ||!$actor->allows(TenantPermissions::MANAGE)||!$this->modules->isEnabled($org,'marketing')||!$this->modules->isEnabled($org,'sales')
            ||!$this->workflow->newRunsEnabled($actor)||$this->campaigns->get($actor,$profile['campaign_id'])===null)
            throw new DomainException('Scheduled attribution authority unavailable.');
        $plan=$profile['plan_id']===null?null:$this->plans->get($actor,$profile['plan_id']);
        if($plan!==null&&$plan['campaign_id']!==$profile['campaign_id'])throw new DomainException('Scheduled paid plan differs.');
        $today=($clock??new DateTimeImmutable('now',new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->setTime(0,0);
        $hash=hash('sha256',json_encode($profile,JSON_THROW_ON_ERROR));$id=$profile['profile_id'];
        return $this->db->transactional(function()use($actor,$org,$who,$profile,$plan,$today,$hash,$id):string{
            $this->db->executeStatement('INSERT INTO marketing_attribution_schedules (organization_id,profile_id,configuration_hash) VALUES (?,?,?) ON DUPLICATE KEY UPDATE profile_id=profile_id',[$org,$id,$hash]);
            $r=$this->db->fetchAssociative('SELECT * FROM marketing_attribution_schedules WHERE organization_id=? AND BINARY organization_id=BINARY ? AND profile_id=? FOR UPDATE',[$org,$org,$id]);
            if(!$r||!hash_equals($r['configuration_hash'],$hash))throw new DomainException('Schedule scope changed; reconcile existing profile first.');
            $day=$today->format('Y-m-d');
            if($r['plan_id']!==null){
                $pid=$r['plan_id'];
                $run=$this->db->fetchOne('SELECT run_id FROM cos_workflow_runs WHERE organization_id=? AND source_plan_id=? AND definition_id=?',[$org,$pid,'federation.approved-plan']);
                $approval=$this->goals->planApprovalAction($actor,$pid);
                if($run===false){
                    if($approval===null){
                        $this->approvals->requestApproval($actor,$pid,'mks-'.substr(hash('sha256',$org.':'.$pid),0,24));
                        return 'PLAN_APPROVAL_REQUESTED';
                    }
                    if($approval['status']!=='COMPLETED')return 'WAIT_PLAN_APPROVAL';
                    $run='run-'.substr(hash('sha256',$org.':'.$pid),0,24);
                    $this->workflow->start($actor,$run,$pid,$approval['id']);
                    return 'RUN_STARTED';
                }
                $status=$this->workflow->status($actor,(string)$run);
                if($status['plan_id']!==$pid||$approval===null||$approval['status']!=='COMPLETED')throw new DomainException('Scheduled run has no trusted plan binding.');
                if($status['state']!=='completed'){
                    if(!in_array($status['state'],['created','running'],true))return 'RECONCILIATION_REQUIRED';
                    $progress=$this->workflow->advance($actor,(string)$run,$approval['id']);
                    return $progress['state']==='manual_reconciliation_required'?'RECONCILIATION_REQUIRED':'RUN_ADVANCED';
                }
                $verified=$this->outcome->verifiedReceipt($actor,(string)$run);
                if($verified['requested_by']!==$who)throw new DomainException('Scheduled receipt requester differs.');
                $native=$verified['receipt'];
                foreach(['campaign_id','plan_id','source_kind','max_pages'] as $key)
                    if(!array_key_exists($key,$native)||$native[$key]!==$profile[$key])throw new DomainException('Scheduled receipt profile mismatch.');
                foreach(['window_start'=>'window_start','window_end'=>'window_end','after'=>'after_cursor'] as $key=>$pin)
                    if(!array_key_exists($key,$native)||$native[$key]!==$r[$pin])throw new DomainException('Scheduled receipt cursor mismatch.');
                $next=$native['next_after'];
                if($next!==null&&(!is_string($next)||!preg_match($profile['source_kind']==='lead'?'/^[1-9][0-9]{0,17}$/D':'/^[a-f0-9]{32}$/D',$next)
                    ||($r['after_cursor']!==null&&($profile['source_kind']==='lead'?(int)$next<=(int)$r['after_cursor']:strcmp($next,$r['after_cursor'])<=0))))
                    throw new DomainException('Scheduled cursor failed to progress.');
                $this->db->executeStatement('UPDATE marketing_attribution_schedules SET after_cursor=?,plan_id=NULL,cycle_exhausted=? WHERE organization_id=? AND profile_id=?',
                    [$next,$next===null?1:0,$org,$id]);
                return $next===null?'CYCLE_SCANNED':'CONTINUATION_READY';
            }
            if($r['cycle_date']===null||(int)$r['cycle_exhausted']===1){
                if($r['cycle_date']===$day)return 'CURRENT_CYCLE_SCANNED';
                $window=MarketingAttributionSchedulePolicy::window($today,$profile['lookback_days'],$plan,$profile['source_kind']);
                if($window===null)return 'NO_ACTIVE_WINDOW';
                [$from,$to]=$window;$cycle=$day;$after=null;
            }else{
                $cycle=$r['cycle_date'];$from=$r['window_start'];$to=$r['window_end'];$after=$r['after_cursor'];
            }
            $suffix=substr(hash('sha256',json_encode([$org,$who,$id,$hash,$cycle,$from,$to,$after],JSON_THROW_ON_ERROR)),0,24);
            $gid='goal-mks-'.$suffix;$pid='plan-mks-'.$suffix;$cap=MarketingAttributionSweepHandler::TYPE;
            $this->goals->createGoal($actor,new GoalSpecification($gid,$org,$who,'Record scheduled bounded conversion collection',
                [['id'=>MarketingAttributionSweepOutcomeProvider::CRITERION,'operator'=>'at_least','expected'=>1]],[$cap],1,null,null,'',[],['independent_plan','independent_action'],'always'));
            $this->goals->proposePlan($actor,$pid,$gid,[['id'=>'sweep','capability_id'=>$cap,'capability_version'=>'1.0.0',
                'input'=>['target_type'=>'marketing_campaign','target_id'=>$profile['campaign_id'],'parameters'=>[
                    'plan_id'=>$profile['plan_id'],'source_kind'=>$profile['source_kind'],'window_start'=>$from,'window_end'=>$to,'after'=>$after,'max_pages'=>$profile['max_pages']]]]]);
            $this->db->executeStatement('UPDATE marketing_attribution_schedules SET cycle_date=?,window_start=?,window_end=?,after_cursor=?,plan_id=?,cycle_exhausted=0 WHERE organization_id=? AND profile_id=?',
                [$cycle,$from,$to,$after,$pid,$org,$id]);
            return 'PLAN_PROPOSED';
        });
    }
}
