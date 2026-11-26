<?php
declare(strict_types=1);

namespace App\Persistence\Sales;

use App\Persistence\Federation\FederationCapabilityBindingResolver;
use App\Persistence\Federation\FederationGoalStore;
use Doctrine\DBAL\Connection;
use DomainException;
use Domains\Sales\Application\Contract\SalesAccessControlInterface;
use Domains\Sales\Application\Contract\SalesWorkspaceScopeInterface;
use Domains\Sales\Automation\Action\SalesGovernedBusinessOperations;
use Domains\Sales\Automation\Action\SalesGovernedOrderHandler;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Orchestration\Goal\CapabilityJsonInputValidator;
use Platform\Orchestration\Goal\GoalSpecification;

/** Signed proposals never authorize a write. Human review creates only a canonical Goal/Plan. */
final readonly class SalesGovernedPlanProposalService
{
    public const KIND='sales.governed_plan.v1';

    public function __construct(private Connection $db,private FederationGoalStore $goals,
        private FederationCapabilityBindingResolver $bindings,private CanonicalCapabilityCatalog $catalog,
        private SalesAccessControlInterface $access,private SalesWorkspaceScopeInterface $scope){}

    public function assertReviewer(TenantContext $actor):void
    {
        $org=$actor->organizationId()->value();$id=$actor->userId()->value();
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)
            ||!ctype_digit($id)||(int)$id<1||(string)(int)$id!==$id
            ||!$this->bindings->isTenantModuleEnabled($actor,'sales')
            ||!$this->bindings->isTenantModuleEnabled($actor,'federation')
            ||!$this->access->hasCapability($org,(int)$id,'sales.admin.agents.manage'))
            throw new DomainException('Active Sales automation reviewer required.');
        // The inbox can contain new customer data and arbitrary entity references.
        // Restrict review to the existing tenant-wide Sales authority, not every manager.
        $this->scope->resolve($org,(int)$id,'tenant',null);
    }

    public static function identities(string $org,string $intent):array
    {
        if(!preg_match('/^intent-[a-f0-9]{24}$/D',$intent))throw new DomainException('Invalid Sales intent.');
        $digest=hash('sha256',$org."\0".$intent."\0".self::KIND);
        return ['goal_id'=>'goal-'.substr($digest,0,24),'plan_id'=>'plan-'.substr($digest,0,24),'digest'=>$digest];
    }

    public function normalize(mixed $payload):array
    {
        if(!is_array($payload)||count($payload)!==3||($payload['kind']??null)!==self::KIND
            ||!is_string($payload['description']??null)||trim($payload['description'])===''||mb_strlen($payload['description'])>500
            ||!is_array($payload['operations']??null)||!array_is_list($payload['operations'])
            ||count($payload['operations'])<1||count($payload['operations'])>20
            ||strlen(json_encode($payload['operations'],JSON_THROW_ON_ERROR))>64000)
            throw new DomainException('Invalid bounded Sales proposal.');
        $seen=[];$validator=new CapabilityJsonInputValidator();
        foreach($payload['operations'] as $op){
            if(!is_array($op)||count($op)!==3||!is_string($op['id']??null)
                ||!preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/D',$op['id'])||isset($seen[$op['id']])
                ||!is_string($op['capability_id']??null)||!is_array($op['parameters']??null))
                throw new DomainException('Invalid Sales proposal step.');
            $seen[$op['id']]=true;
            $input=self::input($op['capability_id'],$op['parameters'],str_repeat('0',32));
            $cap=$this->catalog->describe($op['capability_id']);
            if($cap===null||$cap->ownerDomain!=='sales'||$cap->kind!=='command'
                ||$cap->executionBinding!=='action:'.$cap->id||$cap->approvalPolicy!=='required'
                ||$cap->idempotency!=='required'||$cap->sideEffectLevel!=='internal'
                ||in_array($cap->lifecycle,['retired','deprecated'],true))
                throw new DomainException('Unsupported Sales proposal capability.');
            $validator->validate($cap,$input);
        }
        $payload['description']=trim($payload['description']);
        return self::sort($payload);
    }

    private static function input(string $type,array $p,string $intent):array
    {
        if(in_array($type,SalesGovernedBusinessOperations::types(),true))
            return SalesGovernedBusinessOperations::proposalTarget($type,$p,$intent);
        if(in_array($type,SalesGovernedOrderHandler::types(),true)){
            $create=$type==='sales.governed.order.create';
            $target=$create?$intent:($p['order_id']??null);
            if(!is_string($target)||!preg_match('/^[a-f0-9]{32}$/D',$target))throw new DomainException('Invalid governed Order target.');
            return ['target_type'=>$create?'sales_order_intent':'sales_order','target_id'=>$target,'parameters'=>$p];
        }
        throw new DomainException('Only allowlisted governed Sales operations are plannable.');
    }

    public function propose(TenantContext $actor,string $intent,array $payload):array
    {
        $this->assertReviewer($actor);
        $payload=$this->normalize($payload);
        $org=$actor->organizationId()->value();$owner=$actor->userId()->value();
        $ids=self::identities($org,$intent);$goalId=$ids['goal_id'];$planId=$ids['plan_id'];
        $steps=[];$allowed=[];$counts=[];$previous=null;
        foreach($payload['operations'] as $op){
            $cap=$this->bindings->requireExecutable($actor,$op['capability_id']);
            $input=self::input($cap->id,$op['parameters'],substr(hash('sha256',$ids['digest'].':'.$op['id']),0,32));
            (new CapabilityJsonInputValidator())->validate($cap,$input);
            // The signed array is ordered. Persist explicit predecessor edges so
            // later business operations cannot be claimed before the prior receipt.
            $steps[]=['id'=>$op['id'],'capability_id'=>$cap->id,'capability_version'=>$cap->version,'input'=>$input,
                'depends_on'=>$previous===null?[]:[$previous]];
            $previous=$op['id'];
            $allowed[$cap->id]=true;
            $criterion=in_array($cap->id,SalesGovernedOrderHandler::types(),true)?SalesOrderRunOutcomeProvider::CRITERION:SalesBusinessRunOutcomeProvider::CRITERION;
            $counts[$criterion]=($counts[$criterion]??0)+1;
        }
        $criteria=[];foreach($counts as $id=>$count)$criteria[]=['id'=>$id,'operator'=>'equals','expected'=>$count];
        $spec=new GoalSpecification($goalId,$org,$owner,$payload['description'],$criteria,array_keys($allowed),
            1,null,null,'',[],['independent_plan','independent_action'],'always');
        return $this->db->transactional(function()use($actor,$org,$owner,$spec,$goalId,$planId,$steps):array{
            $old=$this->db->fetchAssociative('SELECT goal_id,plan_json FROM cos_federation_plans WHERE organization_id=? AND BINARY organization_id=BINARY ? AND plan_id=?',[$org,$org,$planId]);
            if($old){
                $stored=$this->goals->specification($actor,$goalId);
                $plan=json_decode($old['plan_json'],true,64,JSON_THROW_ON_ERROR);
                $actual=[];foreach($plan['steps']??[] as $step)$actual[]=array_intersect_key($step,array_flip(['id','capability_id','capability_version','input','depends_on']));
                if($old['goal_id']!==$goalId||$stored===null||$stored->ownerId!==$owner
                    ||self::sort($stored->toArray())!==self::sort($spec->toArray())||self::sort($actual)!==self::sort($steps))
                    throw new DomainException('Sales proposal conflicts with existing Goal/Plan.');
            }else{
                $this->goals->createGoal($actor,$spec);$this->goals->proposePlan($actor,$planId,$goalId,$steps);
            }
            return ['goal_id'=>$goalId,'plan_id'=>$planId,'operation_count'=>count($steps),'approval_required'=>true,'execution_started'=>false];
        });
    }

    private static function sort(mixed $v):mixed
    {
        if(!is_array($v))return $v;
        if(!array_is_list($v))ksort($v,SORT_STRING);
        foreach($v as $k=>$child)$v[$k]=self::sort($child);
        return $v;
    }
}
