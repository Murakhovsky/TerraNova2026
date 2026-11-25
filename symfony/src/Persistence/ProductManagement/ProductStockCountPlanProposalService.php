<?php
declare(strict_types=1);

namespace App\Persistence\ProductManagement;

use App\Persistence\Federation\FederationGoalStore;
use Doctrine\DBAL\Connection;
use DomainException;
use Domains\ProductManagement\Application\Service\ProductManagementService;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Orchestration\Goal\GoalSpecification;

/** Proposes an immutable physical count; only the approved worker may apply it. */
final readonly class ProductStockCountPlanProposalService
{
    public const CAPABILITY='product_management.stock.count.apply';
    public const CRITERION='product_management.stock_count_verified';
    public function __construct(private Connection $db,private ProductManagementService $products,private FederationGoalStore $goals){}
    public function propose(TenantContext $actor,string $variant,string $location,string $counted,int $revision,string $source,string $key):array
    {
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::MANAGE))throw new DomainException('Tenant manager required.');
        foreach([$variant,$location,$key] as $id)if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Invalid count identity.');
        if($revision<0||!preg_match('#^count:[A-Za-z0-9._:/-]{1,150}$#D',$source))throw new DomainException('Count document and revision required.');
        $counted=ProductManagementService::countQuantity($counted);
        $org=$actor->organizationId()->value();
        $countId=substr(hash('sha256',$org."\0".$key."\0".'stock-count'),0,32);
        $goalId='goal-'.substr($countId,0,24);$planId='plan-'.substr($countId,0,24);
        $input=['target_type'=>'product_stock_count','target_id'=>$countId,'parameters'=>[
            'variant_id'=>$variant,'location_id'=>$location,'counted'=>$counted,'expected_revision'=>$revision,'source_ref'=>$source]];
        return $this->db->transactional(function()use($actor,$org,$variant,$location,$revision,$countId,$goalId,$planId,$input):array{
            $existing=$this->db->fetchAssociative('SELECT p.plan_json,g.owner_id FROM cos_federation_plans p JOIN cos_federation_goals g ON g.organization_id=p.organization_id AND g.goal_id=p.goal_id WHERE p.organization_id=? AND p.plan_id=?',[$org,$planId]);
            if($existing){
                $plan=json_decode((string)$existing['plan_json'],true,32,JSON_THROW_ON_ERROR);
                $stored=$plan['steps'][0]['input']??null;
                if($existing['owner_id']!==$actor->userId()->value()||!is_array($stored)||count($plan['steps'])!==1
                    ||($plan['steps'][0]['capability_id']??null)!==self::CAPABILITY||($stored['target_type']??null)!==$input['target_type']
                    ||($stored['target_id']??null)!==$countId||!is_array($stored['parameters']??null))
                    throw new DomainException('Count proposal identity conflict.');
                $a=$stored['parameters'];$b=$input['parameters'];ksort($a);ksort($b);
                if($a!==$b)throw new DomainException('Count proposal payload conflict.');
                return ['count_id'=>$countId,'goal_id'=>$goalId,'plan_id'=>$planId];
            }
            $balance=$this->products->balance($org,$variant,$location);
            if((int)$balance['revision']!==$revision)throw new DomainException('Stock changed; recount before proposing.');
            $goal=new GoalSpecification($goalId,$org,$actor->userId()->value(),'Independently verify approved physical count '.$countId,
                [['id'=>self::CRITERION,'operator'=>'at_least','expected'=>1]],[self::CAPABILITY],1,null,null,'',[],['independent_plan','independent_action'],'always');
            $this->goals->createGoal($actor,$goal);
            $this->goals->proposePlan($actor,$planId,$goalId,[['id'=>'stock-count-apply','capability_id'=>self::CAPABILITY,'capability_version'=>'1.0.0','input'=>$input]]);
            return ['count_id'=>$countId,'goal_id'=>$goalId,'plan_id'=>$planId];
        });
    }
}
