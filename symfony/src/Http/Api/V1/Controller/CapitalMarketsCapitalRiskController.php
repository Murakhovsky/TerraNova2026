<?php
declare(strict_types=1);
namespace App\Http\Api\V1\Controller;
use App\Security\SessionCsrfValidator;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
use Domains\CapitalMarkets\Application\Audit\CapitalMarketsAuditTrail;
use Domains\CapitalMarkets\Application\Audit\CapitalMarketsAuditAction;
use Domains\CapitalMarkets\Application\Audit\CapitalMarketsAuditResourceType;
use Domains\CapitalMarkets\Application\Service\CapitalRiskService;
use Domains\CapitalMarkets\Application\Service\CapitalMarketsPortfolioAgentService;
use Domains\CapitalMarkets\Model\CapitalMarketsCapability;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;
final readonly class CapitalMarketsCapitalRiskController
{
 public function __construct(private TenantContextProviderInterface $tenants,private ActiveModuleResolver $modules,private CapitalMarketsAccessControlInterface $access,private CapitalRiskService $service,private CapitalMarketsPortfolioAgentService $portfolioAgent,private CapitalMarketsAuditTrail $audit,private SessionCsrfValidator $csrf){}
 public function portfolio():JsonResponse{return $this->read(CapitalMarketsCapability::PortfolioView,fn($o)=>$this->service->workspace($o));}
 public function exposure():JsonResponse{return $this->read(CapitalMarketsCapability::PortfolioView,fn($o)=>$this->service->refreshExposure($o));}
 public function risk(Request $r):JsonResponse{return $this->write($r,CapitalMarketsCapability::RiskView,fn($o,$p)=>$this->service->refreshRisk($o,$p),200);}
 public function setRiskEnvelope(Request $r):JsonResponse{return $this->write($r,CapitalMarketsCapability::RiskManagePolicy,function($o,$p,$actor)use($r){$v=$this->service->saveRiskEnvelope($o,$p);$this->audit($r,$o,$actor,CapitalMarketsAuditAction::RiskEnvelopeUpdated,CapitalMarketsAuditResourceType::RiskEnvelope,(string)$v['envelope_id'],$v);return $v;},201,true);}
 public function simulateOpportunity(Request $r):JsonResponse{return $this->write($r,CapitalMarketsCapability::PortfolioView,function($o,$p){return $this->service->simulateOpportunityImpact($o,(string)($p['opportunity_id']??''),(string)($p['capital']??'0'));},200);}
 public function allocation():JsonResponse{return $this->read(CapitalMarketsCapability::AllocationView,fn($o)=>$this->service->workspace($o)['allocation_plan']);}
 public function allocations():JsonResponse{return $this->read(CapitalMarketsCapability::AllocationView,fn($o)=>$this->service->workspace($o)['strategy_allocations']);}
 public function assignStrategyAllocation(Request $r):JsonResponse{return $this->write($r,CapitalMarketsCapability::PortfolioManage,function($o,$p,$actor)use($r){$v=$this->service->assignStrategyAllocation($o,$p);$this->audit($r,$o,$actor,CapitalMarketsAuditAction::StrategyAllocationChanged,CapitalMarketsAuditResourceType::StrategyAllocation,(string)$v['allocation_id'],$v);return $v;},201,true);}
 public function riskState():JsonResponse{return $this->read(CapitalMarketsCapability::RiskView,fn($o)=>$this->service->workspace($o)['risk']);}
 public function rebalanceCurrent():JsonResponse{return $this->read(CapitalMarketsCapability::AllocationView,fn($o)=>$this->service->workspace($o)['rebalance']);}
 public function recalculate(Request $r):JsonResponse{return $this->write($r,CapitalMarketsCapability::AllocationPropose,function($o,$p,$actor)use($r){$p['proposal_actor_type']=$p['proposal_actor_type']??'HUMAN';$p['proposal_actor_id']=$p['proposal_actor_id']??(string)$actor;$v=$this->service->recalculateAllocation($o,$p);$this->audit($r,$o,$actor,CapitalMarketsAuditAction::AllocationPlanCreated,CapitalMarketsAuditResourceType::AllocationPlan,(string)$v['plan_id'],$v);return $v;},201,true);}
 public function stress(Request $r):JsonResponse{return $this->write($r,CapitalMarketsCapability::RiskView,function($o,$p,$actor)use($r){$v=$this->service->runStress($o,$p);$this->audit($r,$o,$actor,CapitalMarketsAuditAction::StressTestRun,CapitalMarketsAuditResourceType::StressResult,(string)$v['result_id'],$v);return $v;},201,true);}
 public function correlation(Request $r):JsonResponse{return $this->write($r,CapitalMarketsCapability::RiskView,fn($o,$p)=>$this->service->refreshCorrelation($o,$p),201);}
 public function rebalance(Request $r):JsonResponse{return $this->write($r,CapitalMarketsCapability::RebalancePropose,function($o,$p,$actor)use($r){$v=$this->service->proposeRebalance($o,$p);$this->audit($r,$o,$actor,CapitalMarketsAuditAction::RebalancePlanCreated,CapitalMarketsAuditResourceType::RebalancePlan,(string)$v['plan_id'],$v);return $v;},201,true);}
 public function runPortfolioAgent(Request $r):JsonResponse{return $this->write($r,CapitalMarketsCapability::AllocationPropose,function($o,$p,$actor)use($r){
  $correlation=trim((string)($p['correlation_id']??''));if($correlation==='')$correlation='CM-PORTFOLIO-'.strtoupper(bin2hex(random_bytes(6)));
  $v=$this->portfolioAgent->run($o,(string)($p['subject_type']??'portfolio'),(string)($p['subject_id']??'paper-master'),(string)($p['question']??'Analyze current portfolio allocation and risk.'),$correlation);
  $this->audit($r,$o,$actor,CapitalMarketsAuditAction::PortfolioAgentRun,CapitalMarketsAuditResourceType::PortfolioAgentRun,(string)($v['final_run_id']??$v['initial_run_id']??$correlation),$v);
  return $v;
 },200,true);}
 public function approve(Request $r,string $id):JsonResponse{return $this->write($r,CapitalMarketsCapability::AllocationApprove,function($o,$p,$actor)use($id,$r){$v=$this->service->approveAndReserve($o,$id,(string)$actor);$this->audit($r,$o,$actor,CapitalMarketsAuditAction::AllocationApproved,CapitalMarketsAuditResourceType::AllocationPlan,$id,$v);return $v;},200,true);}
 private function read(CapitalMarketsCapability $cap,callable $fn):JsonResponse{try{$c=$this->context($cap);if($c instanceof JsonResponse)return $c;[$t]=$c;return new JsonResponse(['data'=>$fn($t->organizationId()->value())]);}catch(Throwable $e){return new JsonResponse(['error'=>'CAPITAL_RISK_READ_FAILED','message'=>$e->getMessage()],422);}}
 private function write(Request $r,CapitalMarketsCapability $cap,callable $fn,int $status,bool $withActor=false):JsonResponse{try{$c=$this->context($cap);if($c instanceof JsonResponse)return $c;[$t]=$c;if(!$this->csrf->isValid($r))return new JsonResponse(['error'=>'INVALID_CSRF'],403);$p=json_decode((string)$r->getContent(),true);if(!is_array($p))$p=$r->request->all();$v=$withActor?$fn($t->organizationId()->value(),$p,$t->userId()->value()):$fn($t->organizationId()->value(),$p);return new JsonResponse(['data'=>$v],$status);}catch(Throwable $e){return new JsonResponse(['error'=>'CAPITAL_RISK_WRITE_FAILED','message'=>$e->getMessage()],422);}}
 private function audit(Request $request,string $organizationId,int $actorId,CapitalMarketsAuditAction $action,CapitalMarketsAuditResourceType $resourceType,string $resourceId,array $next):void
 {
  $correlation=trim((string)$request->headers->get('X-Correlation-ID',''));
  if($correlation==='')$correlation='CM-CR-'.strtoupper(bin2hex(random_bytes(6)));
  $this->audit->record($organizationId,$actorId,$action,$resourceType,$resourceId,[],$next,$correlation);
 }
 private function context(CapitalMarketsCapability $cap):array|JsonResponse{$t=$this->tenants->requireTenant();$o=$t->organizationId()->value();if(!$this->modules->isEnabled($o,'capital_markets'))return new JsonResponse(['error'=>'MODULE_DISABLED'],404);if(!$this->access->hasCapability($o,$t->userId()->value(),$cap->value)&&!$this->access->hasCapability($o,$t->userId()->value(),CapitalMarketsCapability::Manage->value))return new JsonResponse(['error'=>'FORBIDDEN'],403);return [$t];}
}
