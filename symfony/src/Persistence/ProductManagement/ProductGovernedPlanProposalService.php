<?php
declare(strict_types=1);
namespace App\Persistence\ProductManagement;

use App\Persistence\Federation\FederationCapabilityBindingResolver;
use App\Persistence\Federation\FederationGoalStore;
use Doctrine\DBAL\Connection;
use DomainException;
use Domains\ProductManagement\Automation\Action\ProductGovernedOperationHandler;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Orchestration\Goal\CapabilityJsonInputValidator;
use Platform\Orchestration\Goal\GoalSpecification;

/** Agent-generated structured proposals enter the existing GoalStore; no approval or execution here. */
final readonly class ProductGovernedPlanProposalService
{
    public function __construct(private Connection $db,private FederationGoalStore $goals,private FederationCapabilityBindingResolver $bindings){}
    public function propose(TenantContext $actor,string $key,string $description,array $operations):array
    {
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)
            ||!$this->bindings->isTenantModuleEnabled($actor,'federation'))throw new DomainException('Authenticated enabled manager required.');
        $description=trim($description);
        if(!preg_match('/^[a-f0-9]{32}$/D',$key)||$description===''||mb_strlen($description)>500
            ||!array_is_list($operations)||count($operations)<1||count($operations)>20
            ||strlen(json_encode($operations,JSON_THROW_ON_ERROR))>64000)throw new DomainException('Invalid bounded Product business proposal.');
        $org=$actor->organizationId()->value();$owner=$actor->userId()->value();
        $digest=hash('sha256',$org."\0".$key."\0".'pm-governed-v1');
        $goalId='goal-'.substr($digest,0,24);$planId='plan-'.substr($digest,0,24);
        $steps=[];$seen=[];$allowed=[];
        foreach($operations as $op){
            if(!is_array($op)||count($op)!==3||!is_string($op['id']??null)||!preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/D',$op['id'])
                ||isset($seen[$op['id']])||!is_string($op['capability_id']??null)||!in_array($op['capability_id'],ProductGovernedOperationHandler::types(),true)
                ||!is_array($op['parameters']??null))throw new DomainException('Unsupported Product proposal step.');
            $seen[$op['id']]=true;$cap=$this->bindings->requireExecutable($actor,$op['capability_id']);
            $input=['target_type'=>'product_governed_operation','target_id'=>substr(hash('sha256',$digest.':'.$op['id']),0,32),'parameters'=>$op['parameters']];
            (new CapabilityJsonInputValidator())->validate($cap,$input);
            $steps[]=['id'=>$op['id'],'capability_id'=>$cap->id,'capability_version'=>$cap->version,'input'=>$input];
            $allowed[$cap->id]=true;
        }
        $spec=new GoalSpecification($goalId,$org,$owner,$description,
            [['id'=>ProductGovernedRunOutcomeProvider::CRITERION,'operator'=>'equals','expected'=>count($steps)]],array_keys($allowed),
            1,null,null,'',[],['independent_plan','independent_action'],'always');
        return $this->db->transactional(function()use($actor,$org,$owner,$spec,$goalId,$planId,$steps):array{
            $old=$this->db->fetchAssociative('SELECT goal_id,plan_json FROM cos_federation_plans WHERE organization_id=? AND plan_id=?',[$org,$planId]);
            if($old){
                $stored=$this->goals->specification($actor,$goalId);
                $plan=json_decode($old['plan_json'],true,64,JSON_THROW_ON_ERROR);
                $actual=[];foreach($plan['steps']??[] as $step)$actual[]=array_intersect_key($step,array_flip(['id','capability_id','capability_version','input']));
                if($old['goal_id']!==$goalId||$stored===null||$stored->ownerId!==$owner
                    ||self::canonical($stored->toArray())!==self::canonical($spec->toArray())||self::canonical($actual)!==self::canonical($steps))
                    throw new DomainException('Proposal key conflicts with the immutable Goal/Plan.');
            }else{
                $this->goals->createGoal($actor,$spec);
                $this->goals->proposePlan($actor,$planId,$goalId,$steps);
            }
            return ['goal_id'=>$goalId,'plan_id'=>$planId,'operation_count'=>count($steps),'approval_required'=>true,'execution_started'=>false];
        });
    }
    private static function canonical(array $value):string
    {
        $sort=static function(mixed $v)use(&$sort):mixed{
            if(!is_array($v))return $v;
            if(!array_is_list($v))ksort($v,SORT_STRING);
            foreach($v as $k=>$child)$v[$k]=$sort($child);
            return $v;
        };
        return json_encode($sort($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    }
}
