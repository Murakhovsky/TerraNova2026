<?php
declare(strict_types=1);
namespace App\Http\Api\V1\Controller;
use App\Security\SessionCsrfValidator;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
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
 public function __construct(private TenantContextProviderInterface $tenants,private ActiveModuleResolver $modules,private CapitalMarketsAccessControlInterface $access,private CapitalRiskService $service,private CapitalMarketsPortfolioAgentService $portfolioAgent,private SessionCsrfValidator $csrf){}
 public function portfolio():JsonResponse{return $this->read(CapitalMarketsCapability::PortfolioView,fn($o)=>$this->service->workspace($o));}
 public function exposure():JsonResponse{return $this->read(CapitalMarketsCapability::PortfolioView,fn($o)=>$this->service->refreshExposure($o));}
 public function risk(Request $r):JsonResponse{return $this->write($r,CapitalMarketsCapability::RiskView,fn($o,$p)=>$this->service->refreshRisk($o,$p),200);}
 public function setRiskEnvelope(Request $r):JsonResponse{return $this->write($r,CapitalMarketsCapability::RiskManagePolicy,fn($o,$p)=>$this->service->saveRiskEnvelope($o,$p),201);}
 public function simulateOpportunity(Request $r):JsonResponse{return $this->write($r,CapitalMarketsCapability::PortfolioView,function($o,$p){return $this->service->simulateOpportunityImpact($o,(string)($p['opportunity_id']??''),(string)($p['capital']??'0'));},200);}
 public function allocation():JsonResponse{return $this->read(CapitalMarketsCapability::AllocationView,fn($o)=>$this->service->workspace($o)['allocation_plan']);}
 public function allocations():JsonResponse{return $this->read(CapitalMarketsCapability::AllocationView,fn($o)=>$this->service->workspace($o)['strategy_allocations']);}
 public function riskState():JsonResponse{return $this->read(CapitalMarketsCapability::RiskView,fn($o)=>$this->service->workspace($o)['risk']);}
 public function rebalanceCurrent():JsonResponse{return $this->read(CapitalMarketsCapability::AllocationView,fn($o)=>$this->service->workspace($o)['rebalance']);}
 public function recalculate(Request $r):JsonResponse{return $this->write($r,CapitalMarketsCapability::AllocationPropose,fn($o,$p)=>$this->service->recalculateAllocation($o,$p),201);}
 public function stress(Request $r):JsonResponse{return $this->write($r,CapitalMarketsCapability::RiskView,fn($o,$p)=>$this->service->runStress($o,$p),201);}
 public function correlation(Request $r):JsonResponse{return $this->write($r,CapitalMarketsCapability::RiskView,fn($o,$p)=>$this->service->refreshCorrelation($o,$p),201);}
 public function rebalance(Request $r):JsonResponse{return $this->write($r,CapitalMarketsCapability::RebalancePropose,fn($o,$p)=>$this->service->proposeRebalance($o,$p),201);}
 public function runPortfolioAgent(Request $r):JsonResponse{return $this->write($r,CapitalMarketsCapability::AllocationPropose,function($o,$p){
  $correlation=trim((string)($p['correlation_id']??''));if($correlation==='')$correlation='CM-PORTFOLIO-'.strtoupper(bin2hex(random_bytes(6)));
  return $this->portfolioAgent->run($o,(string)($p['subject_type']??'portfolio'),(string)($p['subject_id']??'paper-master'),(string)($p['question']??'Analyze current portfolio allocation and risk.'),$correlation);
 },200);}
 public function approve(Request $r,string $id):JsonResponse{return $this->write($r,CapitalMarketsCapability::AllocationApprove,function($o,$p,$actor)use($id){return $this->service->approveAndReserve($o,$id,(string)$actor);},200,true);}
 private function read(CapitalMarketsCapability $cap,callable $fn):JsonResponse{try{$c=$this->context($cap);if($c instanceof JsonResponse)return $c;[$t]=$c;return new JsonResponse(['data'=>$fn($t->organizationId()->value())]);}catch(Throwable $e){return new JsonResponse(['error'=>'CAPITAL_RISK_READ_FAILED','message'=>$e->getMessage()],422);}}
 private function write(Request $r,CapitalMarketsCapability $cap,callable $fn,int $status,bool $withActor=false):JsonResponse{try{$c=$this->context($cap);if($c instanceof JsonResponse)return $c;[$t]=$c;if(!$this->csrf->isValid($r))return new JsonResponse(['error'=>'INVALID_CSRF'],403);$p=json_decode((string)$r->getContent(),true);if(!is_array($p))$p=$r->request->all();$v=$withActor?$fn($t->organizationId()->value(),$p,$t->userId()->value()):$fn($t->organizationId()->value(),$p);return new JsonResponse(['data'=>$v],$status);}catch(Throwable $e){return new JsonResponse(['error'=>'CAPITAL_RISK_WRITE_FAILED','message'=>$e->getMessage()],422);}}
 private function context(CapitalMarketsCapability $cap):array|JsonResponse{$t=$this->tenants->requireTenant();$o=$t->organizationId()->value();if(!$this->modules->isEnabled($o,'capital_markets'))return new JsonResponse(['error'=>'MODULE_DISABLED'],404);if(!$this->access->hasCapability($o,$t->userId()->value(),$cap->value)&&!$this->access->hasCapability($o,$t->userId()->value(),CapitalMarketsCapability::Manage->value))return new JsonResponse(['error'=>'FORBIDDEN'],403);return [$t];}
}
