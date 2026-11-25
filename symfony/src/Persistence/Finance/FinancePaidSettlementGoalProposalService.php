<?php
declare(strict_types=1);
namespace App\Persistence\Finance;

use App\Persistence\Federation\FederationGoalStore;
use App\Persistence\Federation\FederationCanonicalWorkflowBridge;
use Doctrine\DBAL\Connection;
use DomainException;
use Domains\Finance\Application\Service\ApprovedFinancePaidSettlementService;
use Domains\Finance\Application\Service\FinancePaidSettlementReviewService;
use Domains\Finance\Domain\BudgetControlPolicy;
use Kernel\Tenant\Model\TenantContext;
use Platform\Orchestration\Goal\GoalSpecification;

final readonly class FinancePaidSettlementGoalProposalService
{
    public const CRITERION='finance.paid.budget_settled';
    public function __construct(private Connection $db,private FederationGoalStore $goals,private FederationCanonicalWorkflowBridge $workflow,
        private FinancePaidSettlementReviewService $reviews){}
    public function propose(TenantContext $actor,string $reservation,string $document,string $snapshot,string $hash,int $revision,string $key):array
    {
        BudgetControlPolicy::key($key);
        if(!$this->workflow->newRunsEnabled($actor))throw new DomainException('Canonical planning disabled.');
        $facts=$this->reviews->read($actor,$reservation,$document,$snapshot);
        $c=$facts['candidate'];
        if($c['candidate_hash']!==$hash||$facts['expected_revision']!==$revision)throw new DomainException('Reviewed candidate or budget revision changed.');
        $org=$actor->organizationId()->value();$cap=ApprovedFinancePaidSettlementService::TYPE;
        $suffix=substr(hash('sha256',json_encode([$org,$actor->userId()->value(),'settle',$key],JSON_THROW_ON_ERROR)),0,24);
        $goal='goal-'.$suffix;$plan='plan-'.$suffix;
        $input=['target_type'=>'finance_reservation','target_id'=>$reservation,'parameters'=>[
            'candidate_hash'=>$hash,'document_ref'=>$document,'expected_revision'=>$revision,'snapshot_ref'=>$snapshot]];
        return $this->db->transactional(function()use($actor,$org,$goal,$plan,$cap,$input,$c):array{
            $existing=$this->db->fetchOne('SELECT plan_json FROM cos_federation_plans WHERE organization_id=:org AND plan_id=:plan AND goal_id=:goal FOR UPDATE',
                ['org'=>$org,'plan'=>$plan,'goal'=>$goal]);
            if(is_string($existing)){
                $steps=json_decode($existing,true,32,JSON_THROW_ON_ERROR)['steps']??[];
                $old=$steps[0]['input']??[];
                if(count($steps)!==1||($steps[0]['capability_id']??null)!==$cap||$old!=$input)
                    throw new DomainException('Finance settlement proposal key conflict.');
                return ['goal_id'=>$goal,'plan_id'=>$plan,'state'=>'existing'];
            }
            $spec=new GoalSpecification($goal,$org,$actor->userId()->value(),
                'Settle reviewed Marketing budget '.$c['reservation_id'].'; commit '.$c['proposed_commit_minor'].' and release '.$c['proposed_release_minor'].' minor units '.$c['currency'],
                [['id'=>self::CRITERION,'operator'=>'at_least','expected'=>1]],[$cap],1,null,null,'',[],
                ['independent_plan','independent_action'],'always');
            $this->goals->createGoal($actor,$spec);
            $this->goals->proposePlan($actor,$plan,$goal,[['id'=>'settle-paid-budget','capability_id'=>$cap,'capability_version'=>'1.0.0','input'=>$input]]);
            return ['goal_id'=>$goal,'plan_id'=>$plan,'state'=>'proposed'];
        });
    }
}
