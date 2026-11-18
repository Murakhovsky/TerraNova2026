<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Persistence;

use DomainException;
use InvalidArgumentException;
use PDO;

/** Tenant-scoped drafts and immutable external observations. No direct Sales/Finance writes. */
final readonly class MySqlMarketingStore {
 public function __construct(private PDO $connection){}

 /** @param array<string,mixed> $input @return array<string,mixed> */
 public function createDraft(string $organizationId,int $actorId,array $input,string $idempotencyKey):array {
  $this->identity($organizationId);
  if($actorId<1||$idempotencyKey===''||strlen($idempotencyKey)>191)
   throw new InvalidArgumentException('Invalid actor or idempotency key.');
  $name=trim((string)($input['name']??''));
  $objective=trim((string)($input['objective']??''));
  $brief=$input['brief']??[];
  $audience=$input['audience']??[];
  $creative=$input['creative']??[];
  $amount=$input['planned_budget_minor']??null;
  $currency=$input['currency']??null;
  if($name===''||mb_strlen($name)>190||!preg_match('/^[a-zA-Z0-9_.-]{2,64}$/D',$objective)
     ||!is_array($brief)||!is_array($audience)||!is_array($creative)
     ||($amount!==null&&(!is_int($amount)||$amount<0))
     ||($currency!==null&&(!is_string($currency)||!preg_match('/^[A-Z]{3}$/D',$currency)))
     ||(($amount===null)!==($currency===null)))
   throw new InvalidArgumentException('Invalid Marketing draft.');
  foreach(['organization_id','tenant_id','actor_id','campaign_id','status','revision'] as $forbidden)
   if(array_key_exists($forbidden,$input))throw new DomainException('Server-owned field in Marketing draft.');
  $snapshot=['name'=>$name,'objective'=>$objective,'brief'=>$brief,'audience'=>$audience,
   'creative'=>$creative,'planned_budget_minor'=>$amount,'currency'=>$currency];
  $encoded=json_encode($snapshot,JSON_THROW_ON_ERROR);
  if(strlen($encoded)>65536)throw new InvalidArgumentException('Campaign draft exceeds size limit.');
  $hash=hash('sha256',$encoded);
  $this->connection->beginTransaction();
  try{
   $receipt=$this->connection->prepare('INSERT IGNORE INTO mkt_operation_receipts(organization_id,idempotency_key,operation,payload_hash) VALUES(?,?,?,?)');
   $receipt->execute([$organizationId,$idempotencyKey,'campaign.create',$hash]);
   if($receipt->rowCount()===0){
    $read=$this->connection->prepare('SELECT operation,payload_hash,result_json FROM mkt_operation_receipts WHERE organization_id=? AND idempotency_key=? FOR UPDATE');
    $read->execute([$organizationId,$idempotencyKey]);
    $old=$read->fetch(PDO::FETCH_ASSOC);
    if(!is_array($old)||$old['operation']!=='campaign.create'||!hash_equals((string)$old['payload_hash'],$hash))
     throw new DomainException('Marketing idempotency conflict.');
    $result=json_decode((string)$old['result_json'],true,32,JSON_THROW_ON_ERROR);
    if(!is_array($result))throw new DomainException('Incomplete Marketing receipt.');
    $this->connection->commit();
    return $result;
   }
   $id=bin2hex(random_bytes(16));
   $write=$this->connection->prepare('INSERT INTO mkt_campaigns(organization_id,campaign_id,name,objective,status,brief_json,audience_json,creative_json,planned_budget_minor,currency,created_by) VALUES(?,?,?,?,"DRAFT",?,?,?,?,?,?)');
   $write->execute([$organizationId,$id,$name,$objective,
    json_encode($brief,JSON_THROW_ON_ERROR),json_encode($audience,JSON_THROW_ON_ERROR),
    json_encode($creative,JSON_THROW_ON_ERROR),$amount,$currency,$actorId]);
   $revision=$this->connection->prepare('INSERT INTO mkt_campaign_revisions(organization_id,campaign_id,revision,snapshot_json,actor_id) VALUES(?,?,1,?,?)');
   $revision->execute([$organizationId,$id,$encoded,$actorId]);
   $event=$this->connection->prepare('INSERT INTO mkt_event_outbox(organization_id,event_id,event_type,aggregate_ref,payload_json) VALUES(?,?,?,?,?)');
   $event->execute([$organizationId,bin2hex(random_bytes(16)),'marketing.campaign.created',$id,
     json_encode(['campaign_id'=>$id,'revision'=>1,'actor_id'=>$actorId],JSON_THROW_ON_ERROR)]);
   $result=['campaign_id'=>$id,'status'=>'DRAFT','revision'=>1];
   $done=$this->connection->prepare('UPDATE mkt_operation_receipts SET result_json=? WHERE organization_id=? AND idempotency_key=?');
   $done->execute([json_encode($result,JSON_THROW_ON_ERROR),$organizationId,$idempotencyKey]);
   $this->connection->commit();
   return $result;
  }catch(\Throwable $e){if($this->connection->inTransaction())$this->connection->rollBack();throw $e;}
 }

 /** @return list<array<string,mixed>> */
 public function campaigns(string $org,string $query='',int $limit=30):array {
  $this->identity($org);$limit=max(1,min(100,$limit));
  $sql='SELECT campaign_id,name,objective,status,planned_budget_minor,currency,revision,created_at,updated_at FROM mkt_campaigns WHERE organization_id=?';
  $args=[$org];
  if($query!==''){$sql.=' AND name LIKE ?';$args[]='%'.str_replace(['%','_'],['\%','\_'],mb_substr($query,0,80)).'%';}
  $statement=$this->connection->prepare($sql.' ORDER BY updated_at DESC LIMIT '.$limit);
  $statement->execute($args);
  return $statement->fetchAll(PDO::FETCH_ASSOC);
 }

 /** @return list<array<string,mixed>> */
 public function accounts(string $org):array {
  $this->identity($org);
  $s=$this->connection->prepare('SELECT account_id,provider,external_account_id,state,timezone,currency,last_verified_at,last_sync_at,last_error_code FROM mkt_paid_accounts WHERE organization_id=? ORDER BY provider,external_account_id LIMIT 100');
  $s->execute([$org]);return $s->fetchAll(PDO::FETCH_ASSOC);
 }

 /** @return array<string,mixed>|null */
 public function account(string $org,string $accountId):?array {
  $this->identity($org);
  $s=$this->connection->prepare('SELECT * FROM mkt_paid_accounts WHERE organization_id=? AND account_id=? LIMIT 1');
  $s->execute([$org,$accountId]);$row=$s->fetch(PDO::FETCH_ASSOC);
  return is_array($row)?$row:null;
 }

 /** Persist externally-managed Meta object; never grant COS_MANAGED on import. */
 public function observeObject(string $org,string $accountId,string $type,array $row):void {
  $this->identity($org);
  if(!in_array($type,['campaign','adset','ad'],true))throw new InvalidArgumentException('Invalid provider object type.');
  $external=(string)($row['id']??'');
  if(!preg_match('/^[0-9]{1,30}$/D',$external))throw new InvalidArgumentException('Invalid provider object id.');
  $parent=$type==='ad'?(string)($row['adset_id']??''):($type==='adset'?(string)($row['campaign_id']??''):'');
  $name=mb_substr((string)($row['name']??''),0,255);
  $sql='INSERT INTO mkt_paid_objects(organization_id,account_id,object_type,external_id,parent_external_id,name,provider_status,effective_status,source_updated_at,source_hash) VALUES(?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE parent_external_id=VALUES(parent_external_id),name=VALUES(name),provider_status=VALUES(provider_status),effective_status=VALUES(effective_status),source_updated_at=VALUES(source_updated_at),source_hash=VALUES(source_hash),imported_at=CURRENT_TIMESTAMP(6)';
  $s=$this->connection->prepare($sql);
  $s->execute([$org,$accountId,$type,$external,$parent!==''?$parent:null,$name,
   (string)($row['status']??'UNKNOWN'),(string)($row['effective_status']??'UNKNOWN'),
   (string)($row['updated_time']??''),hash('sha256',json_encode($row,JSON_THROW_ON_ERROR))]);
 }

 /** No mixed-grain summation: a caller chooses one reporting level. */
 public function insightSummary(string $org,string $accountId,string $grain,string $since,string $until):array {
  $this->identity($org);
  if(!in_array($grain,['account','campaign','adset','ad'],true)||!preg_match('/^\d{4}-\d\d-\d\d$/D',$since)||!preg_match('/^\d{4}-\d\d-\d\d$/D',$until)||$until<$since)
   throw new InvalidArgumentException('Invalid insight selection.');
  $s=$this->connection->prepare('SELECT currency, SUM(spend_minor) AS spend_minor,SUM(impressions) AS impressions,SUM(clicks) AS clicks,MAX(fetched_at) AS last_fetched_at FROM mkt_paid_insight_snapshots WHERE organization_id=? AND account_id=? AND grain=? AND report_date BETWEEN ? AND ? GROUP BY currency ORDER BY currency');
  $s->execute([$org,$accountId,$grain,$since,$until]);
  return $s->fetchAll(PDO::FETCH_ASSOC);
 }
 private function identity(string $org):void {
  if($org===''||strlen($org)>190)throw new InvalidArgumentException('Missing trusted tenant.');
 }
}
