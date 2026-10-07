<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Application\Service;
use DateTimeImmutable;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsTradingRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\CapitalRiskRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\ResearchLabRepositoryInterface;
use Domains\CapitalMarkets\Domain\Allocation\AllocationPolicy;
use Domains\CapitalMarkets\Domain\Portfolio\PortfolioRiskState;
use Domains\CapitalMarkets\Domain\Service\CapitalAllocationEngine;
use Domains\CapitalMarkets\Domain\Service\EconomicExposureEngine;
use Domains\CapitalMarkets\Domain\Service\PortfolioStressEngine;
use Domains\CapitalMarkets\Domain\Stress\PortfolioStressScenario;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use RuntimeException;
final readonly class CapitalRiskService
{
 public function __construct(
  private CapitalMarketsTradingRepositoryInterface $trading,
  private ResearchLabRepositoryInterface $research,
  private CapitalRiskRepositoryInterface $repository,
  private EconomicExposureEngine $exposure,
  private CapitalAllocationEngine $allocator,
  private PortfolioStressEngine $stress,
 ){}
 public function workspace(string $organizationId,string $portfolioId='paper-master'):array
 {
  $portfolio=$this->trading->paperPortfolio($organizationId)??[];
  $positions=$this->trading->listPositions($organizationId,1000);
  $balances=$this->trading->listPaperBalances($organizationId);
  $opportunities=$this->trading->listOpportunities($organizationId,200);
  return [
   'portfolio'=>$portfolio,'positions'=>$positions,'balances'=>$balances,'opportunities'=>$opportunities,
   'exposure'=>$this->repository->latestExposureSnapshot($organizationId,$portfolioId),
   'risk'=>$this->repository->latestRiskSnapshot($organizationId,$portfolioId),
   'risk_envelope'=>$this->repository->latestRiskEnvelope($organizationId,$portfolioId),
   'allocation_plan'=>$this->repository->latestAllocationPlan($organizationId,$portfolioId),
   'strategy_allocations'=>$this->repository->listStrategyAllocations($organizationId,$portfolioId),
   'rebalance'=>$this->repository->latestRebalancePlan($organizationId,$portfolioId),
   'correlation'=>$this->repository->latestCorrelationSnapshot($organizationId,$portfolioId),
   'stress_results'=>$this->repository->listStressResults($organizationId,$portfolioId,20),
  ];
 }
 public function refreshExposure(string $organizationId,string $portfolioId='paper-master'):array
 {
  $raw=$this->trading->listPositions($organizationId,1000);$positions=[];
  foreach($raw as $p){
   $payload=is_array($p['payload']??null)?$p['payload']:$p;
   $quantity=(string)($payload['quantity']??$p['quantity']??'0');$mark=(string)($payload['mark_price']??$payload['markPrice']??$p['mark_price']??'0');
   $notional=DecimalMath::multiply(Decimal::fromString($quantity),Decimal::fromString($mark))->value();
   $positions[]=[
    'instrument_id'=>(string)($p['instrument_id']??$payload['instrument_id']??'unknown'),
    'underlying_key'=>(string)($payload['underlying_key']??$payload['underlying']??''),
    'notional'=>$notional,'side'=>(string)($payload['side']??$p['side']??'LONG'),
    'venue'=>(string)($p['venue_id']??$payload['venue_id']??''),'strategy'=>(string)($p['strategy_id']??$payload['strategy_id']??''),
    'asset'=>(string)($payload['asset']??$payload['symbol']??''),'currency'=>(string)($payload['currency']??$payload['quote_asset']??'USD'),
    'counterparty'=>(string)($payload['counterparty']??$p['venue_id']??''),'chain'=>(string)($payload['chain']??''),
    'liquidity_bucket'=>(string)($payload['liquidity_bucket']??'UNKNOWN'),
    'relationship_valid'=>(bool)($payload['relationship_valid']??($payload['underlying_key']??null)!==null),
    'delta'=>(string)($payload['delta']??'1'),
   ];
  }
  $snapshot=$this->exposure->snapshot($portfolioId,$positions);
  $record=['snapshot_id'=>'exp-'.bin2hex(random_bytes(10)),'portfolio_id'=>$portfolioId,'created_at'=>$snapshot->timestamp->format('Y-m-d H:i:s.u'),'gross_exposure'=>$snapshot->grossExposure->value(),'net_exposure'=>$snapshot->netExposure->value(),'by_underlying'=>$this->decimalMap($snapshot->byUnderlying),'by_asset'=>$this->decimalMap($snapshot->byAsset),'by_venue'=>$this->decimalMap($snapshot->byVenue),'by_strategy'=>$this->decimalMap($snapshot->byStrategy),'by_currency'=>$this->decimalMap($snapshot->byCurrency),'by_counterparty'=>$this->decimalMap($snapshot->byCounterparty),'by_chain'=>$this->decimalMap($snapshot->byChain),'by_liquidity_bucket'=>$this->decimalMap($snapshot->byLiquidityBucket),'unknown_exposure'=>$snapshot->unknownExposure];
  $this->repository->saveExposureSnapshot($organizationId,$record);return $record;
 }
 public function recalculateAllocation(string $organizationId,array $input,string $portfolioId='paper-master'):array
 {
  $portfolio=$this->trading->paperPortfolio($organizationId)??throw new RuntimeException('Paper portfolio is not initialized.');
  $available=Decimal::fromString((string)($input['available_capital']??$portfolio['available_capital']??$portfolio['cash']??'0'));
  $mode=strtoupper((string)($input['mode']??'BALANCED'));$version=(string)($input['policy_version']??'v1');
  $policy=new AllocationPolicy('policy-'.$mode.'-'.$version,$version,'SCORE_BASED',$mode,['score_multiplier'=>1.0],(array)($input['constraints']??[]));
  $state=PortfolioRiskState::tryFrom(strtoupper((string)($input['risk_state']??'NORMAL')))??PortfolioRiskState::Normal;
  $opportunities=(array)($input['opportunities']??[]);
  $plan=$this->allocator->allocate($portfolioId,$available,$opportunities,$policy,$state,(array)($input['hard_caps']??[]));
  $record=['plan_id'=>$plan->id,'portfolio_id'=>$portfolioId,'created_at'=>$plan->createdAt->format('Y-m-d H:i:s.u'),'capital_available'=>$plan->capitalAvailable->value(),'allocations'=>array_map(fn($i)=>['strategy_version_id'=>$i->strategyVersionId,'opportunity_id'=>$i->opportunityId,'requested_capital'=>$i->requestedCapital->value(),'approved_capital'=>$i->approvedCapital->value(),'priority'=>$i->priority,'expected_net_return'=>$i->expectedNetReturn->value(),'expected_value'=>$i->expectedValue->value(),'capacity'=>$i->capacity->value(),'decision'=>$i->decision,'reason'=>$i->reason,'risk_budget'=>$i->riskBudget],$plan->allocations),'reservations'=>$plan->reservations,'expected_return'=>$plan->expectedReturn->value(),'expected_risk'=>$plan->expectedRisk,'expected_liquidity'=>$plan->expectedLiquidity,'expected_drawdown'=>$plan->expectedDrawdown->value(),'constraints'=>$plan->constraints,'reasoning_summary'=>$plan->reasoningSummary,'status'=>$plan->status,'policy_version'=>$plan->policyVersion,'input_fingerprint'=>$plan->inputFingerprint];
  $this->repository->saveAllocationPlan($organizationId,$record);return $record;
 }
 public function runStress(string $organizationId,array $input,string $portfolioId='paper-master'):array
 {
  $portfolio=$this->trading->paperPortfolio($organizationId)??[];
  $capital=Decimal::fromString((string)($portfolio['equity']??$portfolio['capital']??$portfolio['initial_capital']??'0'));
  $positions=[];foreach($this->trading->listPositions($organizationId,1000) as $p){$payload=is_array($p['payload']??null)?$p['payload']:$p;$q=Decimal::fromString((string)($payload['quantity']??'0'));$m=Decimal::fromString((string)($payload['mark_price']??'0'));$positions[]=['position_id'=>(string)($p['position_id']??''),'asset'=>(string)($payload['asset']??$payload['symbol']??''),'venue'=>(string)($p['venue_id']??''),'notional'=>DecimalMath::multiply($q,$m)->value()];}
  $scenario=new PortfolioStressScenario((string)($input['scenario_id']??'custom'),(string)($input['name']??'Custom scenario'),(array)($input['shocks']??[]));
  $result=$this->stress->run($scenario,$capital,$positions);$record=['result_id'=>'stress-'.bin2hex(random_bytes(10)),'portfolio_id'=>$portfolioId,'created_at'=>(new DateTimeImmutable())->format('Y-m-d H:i:s.u'),'scenario_id'=>$result->scenarioId,'estimated_loss'=>$result->estimatedLoss->value(),'margin_impact'=>$result->marginImpact->value(),'positions_affected'=>$result->positionsAffected,'venues_affected'=>$result->venuesAffected,'risk_limits_breached'=>$result->riskLimitsBreached,'capital_remaining'=>$result->capitalRemaining->value()];
  $this->repository->saveStressResult($organizationId,$record);return $record;
 }
 public function approveAllocation(string $organizationId,string $planId,string $actorId):bool{return $this->repository->approveAllocation($organizationId,$planId,$actorId,gmdate('Y-m-d H:i:s'));}
 private function decimalMap(array $values):array{$out=[];foreach($values as $k=>$v)$out[$k]=$v instanceof Decimal?$v->value():(string)$v;return $out;}
}
