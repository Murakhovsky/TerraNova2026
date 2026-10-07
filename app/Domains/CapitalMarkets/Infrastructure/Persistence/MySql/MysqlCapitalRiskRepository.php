<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;
use Domains\CapitalMarkets\Application\Contract\CapitalRiskRepositoryInterface;
use PDO;
use RuntimeException;
final readonly class MysqlCapitalRiskRepository implements CapitalRiskRepositoryInterface
{
 public function __construct(private PDO $connection){}
 public function saveExposureSnapshot(string $o,array $r):void{$this->insert('tn_capital_market_exposure_snapshots',$o,$r,'snapshot_id');}
 public function latestExposureSnapshot(string $o,string $p):?array{return $this->latest('tn_capital_market_exposure_snapshots',$o,$p);}
 public function saveRiskSnapshot(string $o,array $r):void{$this->insert('tn_capital_market_portfolio_risk_snapshots',$o,$r,'snapshot_id');}
 public function latestRiskSnapshot(string $o,string $p):?array{return $this->latest('tn_capital_market_portfolio_risk_snapshots',$o,$p);}
 public function saveRiskEnvelope(string $o,array $r):void{$this->insert('tn_capital_market_risk_envelopes',$o,$r,'envelope_id');}
 public function latestRiskEnvelope(string $o,string $p):?array{return $this->latest('tn_capital_market_risk_envelopes',$o,$p);}
 public function saveAllocationPolicy(string $o,array $r):void{$this->insert('tn_capital_market_allocation_policies',$o,$r,'policy_id');}
 public function latestAllocationPolicy(string $o,string $p,string $mode):?array{$s=$this->connection->prepare('SELECT record_json FROM tn_capital_market_allocation_policies WHERE organization_id=:o AND portfolio_id=:p AND mode=:m ORDER BY id DESC LIMIT 1');$s->execute(['o'=>$o,'p'=>$p,'m'=>$mode]);$v=$s->fetchColumn();return $v===false?null:$this->decode((string)$v);}
 public function saveAllocationPlan(string $o,array $r):void{$this->insert('tn_capital_market_allocation_plans',$o,$r,'plan_id');}
 public function latestAllocationPlan(string $o,string $p):?array{return $this->latest('tn_capital_market_allocation_plans',$o,$p);}
 public function approveAllocation(string $o,string $planId,string $actorId,string $approvedAt):bool{$s=$this->connection->prepare("UPDATE tn_capital_market_allocation_plans SET status='APPROVED',approved_by=:a,approved_at=:t WHERE organization_id=:o AND plan_id=:id AND status='PROPOSED'");$s->execute(['a'=>$actorId,'t'=>$approvedAt,'o'=>$o,'id'=>$planId]);return $s->rowCount()===1;}
 public function saveStrategyAllocation(string $o,array $r):void{$this->insert('tn_capital_market_strategy_allocations',$o,$r,'allocation_id');}
 public function listStrategyAllocations(string $o,string $p):array{return $this->many('tn_capital_market_strategy_allocations',$o,$p,500);}
 public function saveRebalancePlan(string $o,array $r):void{$this->insert('tn_capital_market_rebalance_plans',$o,$r,'plan_id');}
 public function latestRebalancePlan(string $o,string $p):?array{return $this->latest('tn_capital_market_rebalance_plans',$o,$p);}
 public function saveCorrelationSnapshot(string $o,array $r):void{$this->insert('tn_capital_market_correlation_snapshots',$o,$r,'snapshot_id');}
 public function latestCorrelationSnapshot(string $o,string $p):?array{return $this->latest('tn_capital_market_correlation_snapshots',$o,$p);}
 public function saveStressResult(string $o,array $r):void{$this->insert('tn_capital_market_stress_results',$o,$r,'result_id');}
 public function listStressResults(string $o,string $p,int $limit=50):array{return $this->many('tn_capital_market_stress_results',$o,$p,$limit);}
 private function insert(string $table,string $o,array $r,string $idKey):void{
  $id=trim((string)($r[$idKey]??''));$p=trim((string)($r['portfolio_id']??''));if($o===''||$id===''||$p==='')throw new RuntimeException('Capital Risk record identity missing.');
  $cols=['organization_id',$idKey,'portfolio_id','record_json','created_at'];$params=['organization_id'=>$o,$idKey=>$id,'portfolio_id'=>$p,'record_json'=>json_encode($r,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION),'created_at'=>$r['created_at']??gmdate('Y-m-d H:i:s')];
  foreach(['version','mode','status','policy_version','input_fingerprint','strategy_version_id','approved_by','approved_at'] as $c){if(isset($r[$c])){$cols[]=$c;$params[$c]=$r[$c];}}
  $quoted=implode(',',$cols);$values=implode(',',array_map(fn($c)=>':'.$c,$cols));$s=$this->connection->prepare('INSERT INTO '.$table.' ('.$quoted.') VALUES ('.$values.')');$s->execute($params);
 }
 private function latest(string $table,string $o,string $p):?array{$s=$this->connection->prepare('SELECT record_json FROM '.$table.' WHERE organization_id=:o AND portfolio_id=:p ORDER BY id DESC LIMIT 1');$s->execute(['o'=>$o,'p'=>$p]);$v=$s->fetchColumn();return $v===false?null:$this->decode((string)$v);}
 private function many(string $table,string $o,string $p,int $limit):array{$limit=max(1,min(1000,$limit));$s=$this->connection->prepare('SELECT record_json FROM '.$table.' WHERE organization_id=:o AND portfolio_id=:p ORDER BY id DESC LIMIT '.$limit);$s->execute(['o'=>$o,'p'=>$p]);return array_map(fn($v)=>$this->decode((string)$v),array_column($s->fetchAll(PDO::FETCH_ASSOC),'record_json'));}
 private function decode(string $json):array{$v=json_decode($json,true,512,JSON_THROW_ON_ERROR);return is_array($v)?$v:[];}
}
