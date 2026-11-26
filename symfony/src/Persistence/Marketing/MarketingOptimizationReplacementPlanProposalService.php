<?php
declare(strict_types=1);
namespace App\Persistence\Marketing;

use App\Persistence\Federation\FederationGoalStore;
use App\Persistence\Federation\FederationCanonicalWorkflowBridge;
use Doctrine\DBAL\Connection;
use DomainException;
use Domains\Marketing\Application\Service\MarketingOptimizationReplacementService;
use Domains\Marketing\Automation\Action\MarketingOptimizationReplacementHandler;
use Domains\Marketing\Domain\MarketingExperimentPolicy;
use Domains\Marketing\Domain\MarketingPlanningPolicy;
use Kernel\Tenant\Model\TenantContext;
use Platform\Orchestration\Goal\GoalSpecification;

/** Deterministic proposal only; Federation owns Plan and Action approvals and execution. */
final readonly class MarketingOptimizationReplacementPlanProposalService
{
    public function __construct(private Connection $db,private FederationGoalStore $goals,
        private FederationCanonicalWorkflowBridge $workflow,private MarketingOptimizationReplacementService $replacements){}
    public function propose(TenantContext $actor,array $input,string $key):array
    {
        MarketingPlanningPolicy::key($key);
        if(!$this->workflow->newRunsEnabled($actor)||($input['target_type']??null)!=='marketing_paid_account')
            throw new DomainException('Canonical Marketing replacement planning unavailable.');
        $ev=$this->replacements->prepare($actor,$input['target_id'],$input['parameters']);
        $input['parameters']['decision_hash']=$ev['decision_hash'];
        $org=$actor->organizationId()->value();
        $suffix=substr(MarketingExperimentPolicy::digest(['optimization-replacement-plan-v1',$org,$actor->userId()->value(),$key]),0,24);
        $goalId='goal-'.$suffix;$planId='plan-'.$suffix;
        return $this->db->transactional(function()use($actor,$org,$goalId,$planId,$input,$ev):array{
            $old=$this->db->fetchOne('SELECT plan_json FROM cos_federation_plans WHERE organization_id=:org AND plan_id=:plan AND goal_id=:goal FOR UPDATE',
                ['org'=>$org,'plan'=>$planId,'goal'=>$goalId]);
            $state='existing';
            if(is_string($old)){
                $snapshot=json_decode($old,true,32,JSON_THROW_ON_ERROR);
                if(count($snapshot['steps']??[])!==1||($snapshot['steps'][0]['capability_id']??null)!==MarketingOptimizationReplacementHandler::TYPE
                    ||MarketingExperimentPolicy::digest($snapshot['steps'][0]['input']??[])!==MarketingExperimentPolicy::digest($input))
                    throw new DomainException('Optimization Plan key conflict.');
            }else{
                $goal=new GoalSpecification($goalId,$org,$actor->userId()->value(),
                    'Create independently approved future Marketing replacement funding candidates for '.$ev['account_id'],
                    [['id'=>MarketingOptimizationReplacementOutcomeProvider::CRITERION,'operator'=>'at_least','expected'=>1]],
                    [MarketingOptimizationReplacementHandler::TYPE],1,null,null,'',[],['independent_plan','independent_action'],'always');
                $this->goals->createGoal($actor,$goal);
                $this->goals->proposePlan($actor,$planId,$goalId,[['id'=>'optimization-replacement','capability_id'=>MarketingOptimizationReplacementHandler::TYPE,
                    'capability_version'=>'1.0.0','input'=>$input]]);
                $state='proposed';
            }
            return ['goal_id'=>$goalId,'plan_id'=>$planId,'state'=>$state,'decision_hash'=>$ev['decision_hash'],
                'drafts_recorded'=>false,'provider_mutation'=>false,'financial_mutation'=>false];
        });
    }
}
