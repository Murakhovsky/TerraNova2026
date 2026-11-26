<?php
declare(strict_types=1);
namespace App\Persistence\Marketing;

use App\Persistence\Federation\FederationGoalStore;
use App\Persistence\Federation\FederationCanonicalWorkflowBridge;
use Doctrine\DBAL\Connection;
use DomainException;
use Domains\Marketing\Application\Service\MarketingPaidLaunchPlanService;
use Domains\Marketing\Application\Service\MarketingPaidOperationsReadService;
use Domains\Marketing\Domain\MarketingPlanningPolicy;
use Domains\Marketing\Domain\MarketingOptimizationPausePolicy;
use Domains\Marketing\Application\Service\MarketingOptimizationPauseGuard;
use Domains\Marketing\Domain\MarketingExperimentPolicy;
use Kernel\Tenant\Model\TenantContext;
use Platform\Orchestration\Goal\GoalSpecification;

/** Draft proposal only; never approves, enqueues or executes a provider request. */
final readonly class MarketingPaidGoalProposalService
{
    public const CRITERION='marketing.paid.command_recorded';
    public const OPERATIONS=['create_paused'=>'DEPLOY_PAUSED','activate'=>'ACTIVATE','pause'=>'PAUSE'];
    public function __construct(private Connection $db,private FederationGoalStore $goals,private FederationCanonicalWorkflowBridge $workflow,
        private MarketingPaidLaunchPlanService $plans,private MarketingPaidOperationsReadService $read,private ?MarketingOptimizationPauseGuard $optimization=null){}
    public function propose(TenantContext $actor,string $id,string $operation,string $key,?string $envelope=null,?string $reservation=null,?array $review=null):array
    {
        MarketingPlanningPolicy::key($key);
        if(!isset(self::OPERATIONS[$operation])||!$this->workflow->newRunsEnabled($actor))throw new DomainException('Paid canonical planning disabled.');
        $plan=$this->plans->get($actor,$id);$status=$this->read->status($actor,$id);
        if(!$status['profile_configured']||$status['manual_reconciliation_required'])throw new DomainException('Paid deployment profile or journal unavailable.');
        $params=['plan_hash'=>$plan['plan_hash'],'configuration_hash'=>$status['configuration_hash']];
        if($review!==null){
            if($operation!=='pause'||$this->optimization===null)throw new DomainException('Review binding only belongs to optimization pause.');
            MarketingOptimizationPausePolicy::pin($review);ksort($review,SORT_STRING);$params['optimization_review']=$review;
        }
        if($operation!=='pause')$this->plans->deploymentContext($actor,$id);
        if($operation==='activate'){
            if(!$status['activation_configured']||$envelope===null||$reservation===null)throw new DomainException('Activation not configured or funding references missing.');
            $fund=$this->plans->funding($actor,$id,$envelope,$reservation);
            if(!$fund['funding_observed'])throw new DomainException('Current Finance reservation required.');
            $params=[...$params,'envelope_id'=>$envelope,'reservation_id'=>$reservation,'finance_revision'=>$fund['finance_revision']];
        }elseif($envelope!==null||$reservation!==null)throw new DomainException('Funding reference only belongs to activation.');
        $org=$actor->organizationId()->value();
        $suffix=substr(hash('sha256',json_encode([$org,$actor->userId()->value(),'paid',$key],JSON_THROW_ON_ERROR)),0,24);
        $goal='goal-'.$suffix;$proposal='plan-'.$suffix;
        $cap='marketing.paid.meta.campaign.'.$operation;
        $input=['target_type'=>'marketing_paid_launch_plan','target_id'=>$id,'parameters'=>$params];
        return $this->db->transactional(function()use($actor,$org,$goal,$proposal,$cap,$input,$id,$operation,$status,$review):array{
            $existing=$this->db->fetchOne('SELECT plan_json FROM cos_federation_plans WHERE organization_id=:org AND plan_id=:plan AND goal_id=:goal FOR UPDATE',
                ['org'=>$org,'plan'=>$proposal,'goal'=>$goal]);
            if(is_string($existing)){
                $steps=json_decode($existing,true,32,JSON_THROW_ON_ERROR)['steps']??[];
                $old=$steps[0]['input']??[];
                if(count($steps)!==1||($steps[0]['capability_id']??null)!==$cap||($old['target_type']??null)!==$input['target_type']
                    ||($old['target_id']??null)!==$input['target_id']||!is_array($old['parameters']??null)
                    ||count($old['parameters'])!==count($input['parameters']))throw new DomainException('Paid proposal key conflict.');
                foreach($input['parameters'] as $field=>$value){if(is_array($value)?(!is_array($old['parameters'][$field]??null)||MarketingExperimentPolicy::digest($old['parameters'][$field])!==MarketingExperimentPolicy::digest($value)):(($old['parameters'][$field]??null)!==$value))throw new DomainException('Paid proposal key conflict.');}
                return ['goal_id'=>$goal,'plan_id'=>$proposal,'state'=>'existing'];
            }
            if($review!==null)$this->optimization?->verify($actor,$id,$review);
            $states=array_column($status['operations'],'state','operation');
            if(isset($states[self::OPERATIONS[$operation]])||($operation!=='create_paused'&&($states['DEPLOY_PAUSED']??null)!=='CONFIRMED')
                ||($operation==='activate'&&isset($states['PAUSE'])))throw new DomainException('Paid operation is not a valid next command.');
            $spec=new GoalSpecification($goal,$org,$actor->userId()->value(),
                'Record approved Meta configured-status operation '.$operation.' for '.$id.'; categories: '.implode(',', $status['special_ad_categories']?:['NONE']).'; countries: '.implode(',', $status['special_ad_category_country']),
                [['id'=>self::CRITERION,'operator'=>'at_least','expected'=>1]],[$cap],1,null,null,'',[],
                ['independent_plan','independent_action'],'always');
            $this->goals->createGoal($actor,$spec);
            $this->goals->proposePlan($actor,$proposal,$goal,[['id'=>'paid-operation','capability_id'=>$cap,'capability_version'=>'1.0.0','input'=>$input]]);
            return ['goal_id'=>$goal,'plan_id'=>$proposal,'state'=>'proposed'];
        });
    }
}
