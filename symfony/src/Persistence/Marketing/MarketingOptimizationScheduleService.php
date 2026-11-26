<?php
declare(strict_types=1);
namespace App\Persistence\Marketing;

use App\Persistence\Federation\FederationCanonicalWorkflowBridge;
use App\Persistence\Federation\FederationGoalStore;
use App\Persistence\Federation\FederationPlanApprovalCoordinator;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use DomainException;
use Domains\Marketing\Application\Service\MarketingMediaAnalyticsService;
use Domains\Marketing\Application\Service\MarketingOptimizationRuleService;
use Domains\Marketing\Domain\MarketingExperimentPolicy;
use Domains\Marketing\Domain\MarketingOptimizationSchedulePolicy;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

/** Automated read-only monitoring and proposal. All business review execution remains canonical. */
final readonly class MarketingOptimizationScheduleService
{
    public function __construct(private Connection $db,private FederationGoalStore $goals,private FederationCanonicalWorkflowBridge $workflow,
        private FederationPlanApprovalCoordinator $approvals,private MarketingOptimizationReviewPlanProposalService $plans,
        private MarketingMediaAnalyticsService $media,private MarketingOptimizationRuleService $rules,private ActiveModuleResolver $modules){}

    public function tick(TenantContext $actor,array $profile,?DateTimeImmutable $clock=null):string
    {
        $p=MarketingOptimizationSchedulePolicy::parse(json_encode([$profile],JSON_THROW_ON_ERROR))[0];
        $org=$actor->organizationId()->value();$who=$actor->userId()->value();
        if($p['organization_id']!==$org||$p['actor_id']!==$who||!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)
            ||!$actor->allows(TenantPermissions::MANAGE)||!$this->modules->isEnabled($org,'marketing')||!$this->modules->isEnabled($org,'federation')
            ||!$this->workflow->newRunsEnabled($actor))throw new DomainException('Active scheduled Marketing authority required.');
        $w=MarketingOptimizationSchedulePolicy::windows($clock??new DateTimeImmutable('now',new DateTimeZone('UTC')),$p['lookback_days']);
        if($w['cycle_date']>gmdate('Y-m-d'))throw new DomainException('Future monitoring cycle.');
        $hash=MarketingExperimentPolicy::digest($p);$id=$p['profile_id'];
        return $this->db->transactional(function()use($actor,$org,$who,$p,$w,$hash,$id):string{
            $this->db->executeStatement('INSERT INTO marketing_optimization_schedules (organization_id,profile_id,configuration_hash) VALUES (?,?,?) ON DUPLICATE KEY UPDATE profile_id=profile_id',[$org,$id,$hash]);
            $r=$this->db->fetchAssociative('SELECT * FROM marketing_optimization_schedules WHERE organization_id=? AND BINARY organization_id=BINARY ? AND profile_id=? FOR UPDATE',[$org,$org,$id]);
            if(!$r||!hash_equals($r['configuration_hash'],$hash))throw new DomainException('Optimization schedule configuration changed; reconcile existing profile.');
            if($r['state']==='RECONCILIATION_REQUIRED')return 'RECONCILIATION_REQUIRED';
            if($r['state']==='PENDING'){
                $pid=$r['plan_id'];$gid=$r['goal_id'];
                $run=$this->db->fetchOne('SELECT run_id FROM cos_workflow_runs WHERE organization_id=? AND source_plan_id=? AND definition_id=?',[$org,$pid,'federation.approved-plan']);
                $approval=$this->goals->planApprovalAction($actor,$pid);
                if($run===false){
                    if($approval===null){$this->approvals->requestApproval($actor,$pid,'mkm-approval-'.substr(hash('sha256',$org.':'.$pid),0,24));return 'PLAN_APPROVAL_REQUESTED';}
                    if($approval['status']!=='COMPLETED'){
                        if(in_array($approval['status'],['FAILED','CANCELLED','REJECTED'],true))return $this->reconcile($org,$id);
                        return 'WAIT_PLAN_APPROVAL';
                    }
                    $run='run-mkm-'.substr(hash('sha256',$org.':'.$pid),0,24);
                    $this->workflow->start($actor,$run,$pid,$approval['id']);
                    return 'RUN_STARTED';
                }
                $status=$this->workflow->status($actor,(string)$run);
                if($status['plan_id']!==$pid||$approval===null||$approval['status']!=='COMPLETED')throw new DomainException('Scheduled review Plan binding changed.');
                if($status['state']!=='completed'){
                    if(!in_array($status['state'],['created','running'],true))return $this->reconcile($org,$id);
                    $advance=$this->workflow->advance($actor,(string)$run,$approval['id']);
                    return $advance['state']==='manual_reconciliation_required'?$this->reconcile($org,$id):'RUN_ADVANCED';
                }
                $evaluationId='eval-mkm-'.substr(hash('sha256',$org.':'.$run),0,24);
                $e=$this->goals->recordEvaluation($actor,$evaluationId,(string)$run);
                if(($e['goal_id']??null)!==$gid||($e['result']??null)!=='satisfied'||($e['criteria'][0]['criterion_id']??null)!==MarketingOptimizationReviewOutcomeProvider::CRITERION
                    ||($e['criteria'][0]['observed']??null)!==1)return $this->reconcile($org,$id);
                $this->db->executeStatement("UPDATE marketing_optimization_schedules SET state='REVIEW_RECORDED',run_id=?,evaluation_id=? WHERE organization_id=? AND profile_id=?",[(string)$run,$evaluationId,$org,$id]);
                return 'REVIEW_RECORDED';
            }
            if($r['cycle_date']!==null&&$r['cycle_date']>=$w['cycle_date'])return 'CURRENT_CYCLE_OBSERVED';
            // Reuse exact complete native batches; import only missing/current-revision data with stable read keys.
            $batch=function(string $from,string $to)use($actor,$org,$who,$p,$w):array{
                try{$v=$this->media->report($actor,$p['account_id'],'campaign',$from,$to,null,null);
                    if(($v['account_revision_current']??null)===true)return $v;
                }catch(DomainException){}
                $key='mkm-import-'.MarketingExperimentPolicy::digest([$org,$who,$p['account_id'],$w['cycle_date'],$from,$to]);
                $this->media->sync($actor,$p['account_id'],'campaign',$from,$to,$key);
                return $this->media->report($actor,$p['account_id'],'campaign',$from,$to,null,null);
            };
            $current=$batch($w['window_start'],$w['window_end']);$baseline=$batch($w['baseline_start'],$w['baseline_end']);
            $parameters=['external_id'=>$p['external_id'],'rule'=>$p['rule'],'threshold_percent'=>$p['threshold_percent'],
                'current_run_id'=>$current['id'],'baseline_run_id'=>$baseline['id'],'window_start'=>$w['window_start'],'window_end'=>$w['window_end'],
                'baseline_start'=>$w['baseline_start'],'baseline_end'=>$w['baseline_end']];
            $signal=$this->rules->evaluate($actor,['account_id'=>$p['account_id'],...$parameters]);
            $state=$signal['status']==='TRIGGERED_REVIEW_REQUIRED'?'PENDING':$signal['status'];$proposal=null;
            if($state==='PENDING'){
                $key='mkm-review-'.MarketingExperimentPolicy::digest([$org,$who,$id,$w['cycle_date'],$signal['evaluation_hash']]);
                $proposal=$this->plans->propose($actor,['target_type'=>'marketing_paid_account','target_id'=>$p['account_id'],
                    'parameters'=>[...$parameters,'evaluation_hash'=>$signal['evaluation_hash']]],$key);
            }
            $this->db->executeStatement('UPDATE marketing_optimization_schedules SET cycle_date=?,state=?,signal_hash=?,signal_json=?,goal_id=?,plan_id=?,run_id=NULL,evaluation_id=NULL WHERE organization_id=? AND profile_id=?',
                [$w['cycle_date'],$state,$signal['evaluation_hash'],json_encode($signal,JSON_THROW_ON_ERROR),$proposal['goal_id']??null,$proposal['plan_id']??null,$org,$id]);
            return $proposal===null?$state:'PLAN_PROPOSED';
        });
    }
    private function reconcile(string $org,string $id):string
    {
        $this->db->executeStatement("UPDATE marketing_optimization_schedules SET state='RECONCILIATION_REQUIRED' WHERE organization_id=? AND profile_id=?",[$org,$id]);
        return 'RECONCILIATION_REQUIRED';
    }
}
