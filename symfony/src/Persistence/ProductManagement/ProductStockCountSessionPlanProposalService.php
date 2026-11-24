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
final readonly class ProductStockCountSessionPlanProposalService
{
    public const CAPABILITY='product_management.stock.count.session.apply';
    public const CRITERION='product_management.stock_count_session_verified';
    public function __construct(private Connection $db,private ProductManagementService $products,private FederationGoalStore $goals){}
    public function propose(TenantContext $actor,string $location,array $rows,string $source,string $key):array
    {
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::MANAGE))throw new DomainException('Tenant manager required.');
        foreach([$location,$key] as $id)if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Invalid count identity.');
        if(!preg_match('#^count:[A-Za-z0-9._:/-]{1,150}$#D',$source))throw new DomainException('Count document and revision required.');
        $rows=ProductManagementService::countSessionRows($rows);
        $org=$actor->organizationId()->value();
        $sessionId=substr(hash('sha256',$org."\0".$key."\0".'stock-count-session'),0,32);
        $goalId='goal-'.substr($sessionId,0,24);$planId='plan-'.substr($sessionId,0,24);
        $input=['target_type'=>'product_stock_count_session','target_id'=>$sessionId,'parameters'=>[
            'location_id'=>$location,'source_ref'=>$source,'rows'=>$rows]];
        return $this->db->transactional(function()use($actor,$org,$rows,$location,$sessionId,$goalId,$planId,$input):array{
            $existing=$this->db->fetchAssociative('SELECT p.plan_json,g.owner_id FROM cos_federation_plans p JOIN cos_federation_goals g ON g.organization_id=p.organization_id AND g.goal_id=p.goal_id WHERE p.organization_id=? AND p.plan_id=?',[$org,$planId]);
            if($existing){
                $plan=json_decode((string)$existing['plan_json'],true,32,JSON_THROW_ON_ERROR);
                $stored=$plan['steps'][0]['input']??null;
                if($existing['owner_id']!==$actor->userId()->value()||!is_array($stored)||count($plan['steps'])!==1
                    ||($plan['steps'][0]['capability_id']??null)!==self::CAPABILITY||($stored['target_type']??null)!==$input['target_type']
                    ||($stored['target_id']??null)!==$sessionId||!is_array($stored['parameters']??null))
                    throw new DomainException('Count proposal identity conflict.');
                $a=$stored['parameters'];$b=$input['parameters'];$a['rows']=ProductManagementService::countSessionRows($a['rows']);ksort($a);ksort($b);
                if($a!==$b)throw new DomainException('Count proposal payload conflict.');
                return ['session_id'=>$sessionId,'goal_id'=>$goalId,'plan_id'=>$planId];
            }
            foreach($rows as $row){
                $balance=$this->products->balance($org,$row['variant_id'],$location);
                if((int)$balance['revision']!==$row['expected_revision'])throw new DomainException('Stock changed; recount before proposing.');
            }
            $goal=new GoalSpecification($goalId,$org,$actor->userId()->value(),'Independently verify approved physical count session '.$sessionId,
                [['id'=>self::CRITERION,'operator'=>'at_least','expected'=>count($rows)]],[self::CAPABILITY],1,null,null,'',[],['independent_plan','independent_action'],'always');
            $this->goals->createGoal($actor,$goal);
            $this->goals->proposePlan($actor,$planId,$goalId,[['id'=>'stock-count-session-apply','capability_id'=>self::CAPABILITY,'capability_version'=>'1.0.0','input'=>$input]]);
            return ['session_id'=>$sessionId,'goal_id'=>$goalId,'plan_id'=>$planId];
        });
    }
}
