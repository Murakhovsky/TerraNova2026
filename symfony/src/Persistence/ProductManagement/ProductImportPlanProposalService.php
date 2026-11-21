<?php
declare(strict_types=1);

namespace App\Persistence\ProductManagement;

use App\Persistence\Federation\FederationGoalStore;
use Doctrine\DBAL\Connection;
use DomainException;
use Domains\ProductManagement\Application\Service\CsvProductImportService;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Orchestration\Goal\GoalSpecification;

/**
 * Product-specific Goal/Plan proposal on the canonical Federation writer.
 * No Action submission/approval or execution occurs here.
 */
final readonly class ProductImportPlanProposalService
{
    public const CAPABILITY='product_management.import.apply';
    public const CRITERION='product_management.csv_products_verified';

    public function __construct(
        private Connection $db,
        private CsvProductImportService $imports,
        private FederationGoalStore $goals,
    ) {}

    /** @return array<string,mixed> */
    public function propose(TenantContext $actor,string $batchId,string $digest,string $rowsDigest):array
    {
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::MANAGE)) {
            throw new DomainException('Product CSV Plan requires an authenticated tenant manager.');
        }
        $org=$actor->organizationId()->value();
        return $this->db->transactional(function()use($actor,$org,$batchId,$digest,$rowsDigest):array{
            $locked=$this->db->fetchOne(
                'SELECT batch_id FROM pm_import_batches WHERE organization_id=:org AND batch_id=:batch FOR UPDATE',
                ['org'=>$org,'batch'=>$batchId]);
            if($locked===false)throw new DomainException('Product CSV Plan batch is missing.');
            $rows=$this->imports->reviewForPlan($org,$batchId,$digest,$rowsDigest);
            $count=count($rows);
            if($count<1||$count>5000)throw new DomainException('Invalid Product CSV Plan row count.');
            $goalId='goal-'.substr(hash('sha256',$org."\0".'product-csv'."\0".$batchId),0,24);
            $planId='plan-'.substr(hash('sha256',$org."\0".'product-csv'."\0".$batchId),0,24);
            $goal=new GoalSpecification(
                $goalId,$org,$actor->userId()->value(),
                'Independently verify '.$count.' native Products from approved CSV '.$batchId,
                [['id'=>self::CRITERION,'operator'=>'at_least','expected'=>$count]],
                [self::CAPABILITY],1,null,null,'',
                [],['independent_plan','independent_action'],'always',
            );
            $this->goals->createGoal($actor,$goal);
            $this->goals->proposePlan($actor,$planId,$goalId,[
                ['id'=>'product-csv-apply',
                 'capability_id'=>self::CAPABILITY,
                 'capability_version'=>'1.0.0',
                 'input'=>['target_type'=>'product_import_batch','target_id'=>$batchId,
                     'parameters'=>['batch_digest'=>$digest,'rows_digest'=>$rowsDigest]]],
            ]);
            $updated=$this->db->executeStatement(
                'UPDATE pm_import_batches SET goal_id=:goal,plan_id=:plan
                 WHERE organization_id=:org AND batch_id=:batch AND state=:staged
                   AND goal_id IS NULL AND plan_id IS NULL',
                ['goal'=>$goalId,'plan'=>$planId,'org'=>$org,'batch'=>$batchId,'staged'=>'staged']);
            if($updated!==1)throw new DomainException('Competing Product CSV Goal/Plan binding was rejected.');
            return ['batch_id'=>$batchId,'goal_id'=>$goalId,'plan_id'=>$planId,
                'ready_count'=>$count,'state'=>'proposed',
                'requires'=>'independent_plan_and_action_approvals'];
        });
    }
}
