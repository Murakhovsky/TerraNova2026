<?php
declare(strict_types=1);

namespace App\Persistence\Property;

use App\Persistence\Federation\FederationGoalStore;
use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Orchestration\Goal\GoalSpecification;

/** Application planner only; never injected into Property's Action handler. */
final readonly class PropertyBulkPlanProposalService
{
    public function __construct(
        private Connection $db,
        private PropertyBulkIntakeService $intake,
        private FederationGoalStore $goals,
    ) {}

    /** @return array<string,mixed> */
    public function propose(TenantContext $actor,string $id,string $digest):array
    {
        if (!$actor->isManager() || !$actor->allows(TenantPermissions::MANAGE)) {
            throw new DomainException('Tenant manager review is required.');
        }
        $org=$actor->organizationId()->value();
        return $this->db->transactional(function()use($actor,$org,$id,$digest):array {
            $this->db->fetchOne('SELECT batch_id FROM cos_property_bulk_batches
                WHERE organization_id=:org AND batch_id=:id FOR UPDATE',
                ['org'=>$org,'id'=>$id]);
            $items=$this->intake->reviewForPlan($actor,$id,$digest);
            foreach($items as $item) {
                $this->db->insert('cos_property_bulk_identity_claims',[
                    'organization_id'=>$org,
                    'fingerprint'=>PropertyBulkIntakeService::fingerprint($item['input']),
                    'batch_id'=>$id,'created_at'=>gmdate('Y-m-d H:i:s'),
                ]);
            }
            $goalId='goal-'.substr(hash('sha256',$org."\0".$id),0,24);
            $planId='plan-'.substr(hash('sha256',$org."\0".$id),0,24);
            $goal=new GoalSpecification(
                $goalId,$org,$actor->userId()->value(),
                'Verify '.count($items).' Property Assets from reviewed batch '.$id,
                [['id'=>'property.bulk_assets_verified','operator'=>'at_least','expected'=>count($items)]],
                [PropertyBulkIntakeService::CAPABILITY],1,null,null,'',
                [],['independent_plan','independent_action'],'always',
            );
            $this->goals->createGoal($actor,$goal);
            $this->goals->proposePlan($actor,$planId,$goalId,[
                ['id'=>'bulk-create',
                 'capability_id'=>PropertyBulkIntakeService::CAPABILITY,
                 'capability_version'=>'1.0.0',
                 'input'=>['target_type'=>'property_bulk_batch','target_id'=>$id,
                           'parameters'=>['batch_digest'=>$digest]]],
            ]);
            $updated=$this->db->executeStatement('UPDATE cos_property_bulk_batches
                SET state=:state,goal_id=:goal,plan_id=:plan,updated_at=UTC_TIMESTAMP(6)
                WHERE organization_id=:org AND batch_id=:id AND state=:expected',[
                'state'=>'planned','goal'=>$goalId,'plan'=>$planId,
                'org'=>$org,'id'=>$id,'expected'=>'staged',
            ]);
            if ($updated!==1) throw new DomainException('Concurrent Property Plan proposal rejected.');
            return ['batch_id'=>$id,'state'=>'planned','goal_id'=>$goalId,
                'plan_id'=>$planId,'requires'=>'independent_plan_and_action_approvals'];
        });
    }
}
