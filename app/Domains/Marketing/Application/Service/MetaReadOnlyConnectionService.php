<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Service;

use Domains\Marketing\Infrastructure\Meta\MetaReadTransportInterface;
use Domains\Marketing\Infrastructure\Meta\MetaAdsReadClient;
use Domains\Marketing\Infrastructure\Persistence\MySqlMarketingStore;
use DomainException;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;
use Platform\Integration\Contract\CredentialVaultInterface;
use Platform\Integration\Model\Credential;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Only admin-controlled read-only connection and bounded Meta GET ingestion.
 * Every provider object remains externally managed. No Meta mutation path exists.
 */
final readonly class MetaReadOnlyConnectionService {
 public function __construct(
  private PDO $connection,
  private CredentialVaultInterface $vault,
  private MetaReadTransportInterface $http,
  private MetaAdsReadClient $meta,
  private MySqlMarketingStore $store,
 ){}

 /** @return array<string,string> */
 public function connect(string $org,string $account,string $credentialRef):array {
  $account=$this->accountId($account);
  if(!preg_match('/^env:\/\/[A-Z][A-Z0-9_]{2,127}$/D',$credentialRef))
   throw new InvalidArgumentException('Credential must be an opaque Vault env reference.');
  $token=$this->token($org,$account,$credentialRef);
  $result=$this->http->get('https://graph.facebook.com/v27.0/act_'.$account.
    '?fields=id,currency,timezone_name,account_status',$token);
  if((string)($result['id']??'')!=='act_'.$account)
   throw new RuntimeException('Meta account readback does not match requested account.');
  $currency=(string)($result['currency']??'');
  $timezone=(string)($result['timezone_name']??'');
  if(!preg_match('/^[A-Z]{3}$/D',$currency)||$timezone===''||strlen($timezone)>64)
   throw new RuntimeException('Meta account currency/timezone unavailable.');
  // Global unique account binding is authoritative. Never update another tenant's
  // record through an ON DUPLICATE KEY clause: reject cross-tenant collisions.
  $this->connection->beginTransaction();
  try {
   $lookup=$this->connection->prepare('SELECT organization_id,account_id FROM mkt_paid_accounts WHERE provider="meta" AND external_account_id=? FOR UPDATE');
   $lookup->execute(['act_'.$account]);$existing=$lookup->fetch(PDO::FETCH_ASSOC);
   if(is_array($existing)&&$existing['organization_id']!==$org)
    throw new DomainException('Ad account already bound to another tenant.');
   if(is_array($existing)){
    $canonical=(string)$existing['account_id'];
    $s=$this->connection->prepare('UPDATE mkt_paid_accounts SET credential_ref=?,timezone=?,currency=?,state="READ_ONLY",capability_scopes_json=?,last_verified_at=CURRENT_TIMESTAMP(6),last_error_code=NULL WHERE organization_id=? AND account_id=?');
    $s->execute([$credentialRef,$timezone,$currency,'["ads_read"]',$org,$canonical]);
   }else{
    $canonical=bin2hex(random_bytes(16));
    $s=$this->connection->prepare('INSERT INTO mkt_paid_accounts(organization_id,account_id,provider,external_account_id,credential_ref,state,timezone,currency,capability_scopes_json,last_verified_at) VALUES(?,?,"meta",?,?,"READ_ONLY",?,?,?,CURRENT_TIMESTAMP(6))');
    $s->execute([$org,$canonical,'act_'.$account,$credentialRef,$timezone,$currency,'["ads_read"]']);
   }
   $this->connection->commit();
  }catch(Throwable $e) {
   if($this->connection->inTransaction())$this->connection->rollBack();
   throw $e;
  }
  return ['account_id'=>$canonical,'provider'=>'meta','state'=>'READ_ONLY',
   'timezone'=>$timezone,'currency'=>$currency];
 }

 /** Bounded and cursor-checkpointed read sync; never clears prior observations. */
 public function sync(string $org,string $accountId,int $maxPages=10):array {
  $row=$this->store->account($org,$accountId);
  if($row===null||(string)$row['provider']!=='meta'||(string)$row['state']!=='READ_ONLY')
   throw new DomainException('Marketing Meta account not available for this tenant.');
  $account=$this->accountId((string)$row['external_account_id']);
  $token=$this->token($org,$account,(string)$row['credential_ref']);
  $pages=0;$imported=0;$completed=0;$maxPages=max(1,min(30,$maxPages));
  foreach(['campaign','adset','ad'] as $stream) {
   $cursor=$this->cursor($org,$accountId,$stream);
   $seen=[];
   while($pages<$maxPages) {
    try {
     $batch=match($stream) {
      'campaign'=>$this->meta->campaigns($account,$token,$cursor),
      'adset'=>$this->meta->adSets($account,$token,$cursor),
      default=>$this->meta->ads($account,$token,$cursor),
     };
     foreach($batch['items'] as $obj){$this->store->observeObject($org,$accountId,$stream,$obj);$imported++;}
     $pages++;
     $next=$batch['next_cursor'];
     if($next!==null&&isset($seen[$next]))throw new RuntimeException('Meta pagination cursor loop.');
     if($next!==null)$seen[$next]=true;
     $this->checkpoint($org,$accountId,$stream,$next,$next===null?'COMPLETE':'PARTIAL');
     $cursor=$next;
     if($next===null){$completed++;break;}
    }catch(Throwable $e){
     $this->checkpoint($org,$accountId,$stream,$cursor,'ERROR','PROVIDER_READ_FAILED');
     throw $e;
    }
   }
   if($pages>=$maxPages)break;
  }
  $s=$this->connection->prepare('UPDATE mkt_paid_accounts SET last_sync_at=CURRENT_TIMESTAMP(6),last_error_code=NULL WHERE organization_id=? AND account_id=?');
  $s->execute([$org,$accountId]);
  return ['imported'=>$imported,'pages'=>$pages,'complete'=>$completed===3];
 }

 /** Provider spend is provisional evidence, never an Expense or Payment. */
 public function syncInsights(string $org,string $accountId,string $since,string $until,int $maxPages=10):array {
  $row=$this->store->account($org,$accountId);
  if($row===null||(string)$row['provider']!=='meta'||(string)$row['state']==='DISCONNECTED')
   throw new DomainException('Marketing Meta account not available.');
  $account=$this->accountId((string)$row['external_account_id']);
  $token=$this->token($org,$account,(string)$row['credential_ref']);
  $currency=(string)$row['currency'];$timezone=(string)$row['timezone'];
  $cursor=null;$pages=0;$observed=0;$seen=[];
  while($pages<max(1,min(30,$maxPages))){
   $batch=$this->meta->insights($account,$token,'campaign',$since,$until,$cursor);
   foreach($batch['items'] as $item){
    $external=(string)($item['campaign_id']??'');
    $date=(string)($item['date_start']??'');
    if(!preg_match('/^[0-9]{1,30}$/D',$external)||!preg_match('/^\d{4}-\d\d-\d\d$/D',$date))
     throw new RuntimeException('Meta Insights identity/date invalid.');
    $amount=self::minor((string)($item['spend']??'0'),$currency);
    $impressions=self::count((string)($item['impressions']??'0'));
    $clicks=self::count((string)($item['clicks']??'0'));
    $payload=json_encode($item,JSON_THROW_ON_ERROR);
    $q=$this->connection->prepare('INSERT INTO mkt_paid_insight_snapshots(organization_id,account_id,grain,external_id,report_date,currency,timezone,attribution_key,spend_minor,impressions,clicks,source_hash,source_payload) VALUES(?,?,"campaign",?,?,?,?,? ,?,?,?, ?,?) ON DUPLICATE KEY UPDATE spend_minor=VALUES(spend_minor),impressions=VALUES(impressions),clicks=VALUES(clicks),source_hash=VALUES(source_hash),source_payload=VALUES(source_payload),status="PROVISIONAL",fetched_at=CURRENT_TIMESTAMP(6)');
    $q->execute([$org,$accountId,$external,$date,$currency,$timezone,'meta_default_v1',$amount,$impressions,$clicks,hash('sha256',$payload),$payload]);
    $observed++;
   }
   $pages++;
   $next=$batch['next_cursor'];
   if($next===null)break;
   if(isset($seen[$next]))throw new RuntimeException('Meta pagination cursor loop.');
   $seen[$next]=true;$cursor=$next;
  }
  return ['observed'=>$observed,'pages'=>$pages,'complete'=>$next===null,'status'=>'PROVISIONAL'];
 }

 private function token(string $org,string $account,string $ref):string {
  $credential=new Credential('marketing-meta-'.substr(hash('sha256',$org.$account),0,32),
   OrganizationId::fromString($org),'act_'.$account,'access_token',$ref,['ads_read']);
  $secrets=$this->vault->resolve($credential);
  $token=$secrets['access_token']??$secrets['token']??'';
  if(!is_string($token)||$token===''||str_contains($token,"\r")||str_contains($token,"\n"))
   throw new RuntimeException('Meta read token unavailable.');
  return $token;
 }
 private function accountId(string $account):string {
  $account=preg_replace('/^act_/','',$account);
  if(!is_string($account)||!preg_match('/^[0-9]{1,30}$/D',$account))
   throw new InvalidArgumentException('Invalid Meta account ID.');
  return $account;
 }
 private function cursor(string $org,string $account,string $stream):?string {
  $s=$this->connection->prepare('SELECT cursor_ref FROM mkt_paid_sync_checkpoints WHERE organization_id=? AND account_id=? AND stream=? AND status IN ("PARTIAL","ERROR")');
  $s->execute([$org,$account,$stream]);$v=$s->fetchColumn();
  return is_string($v)&&$v!==''?$v:null;
 }
 private function checkpoint(string $org,string $account,string $stream,?string $cursor,string $status,?string $error=null):void {
  $s=$this->connection->prepare('INSERT INTO mkt_paid_sync_checkpoints(organization_id,account_id,stream,cursor_ref,status,last_success_at,last_error_code) VALUES(?,?,?,?,?,IF(?="COMPLETE",CURRENT_TIMESTAMP(6),NULL),?) ON DUPLICATE KEY UPDATE cursor_ref=VALUES(cursor_ref),status=VALUES(status),last_success_at=IF(VALUES(status)="COMPLETE",CURRENT_TIMESTAMP(6),last_success_at),last_error_code=VALUES(last_error_code)');
  $s->execute([$org,$account,$stream,$cursor,$status,$status,$error]);
 }
 private static function minor(string $amount,string $currency):int {
  $scale=match($currency){'USD','EUR','UAH','PLN','GBP'=>2,'JPY'=>0,default=>throw new DomainException('Unsupported currency scale.')};
  if(!preg_match('/^(0|[1-9][0-9]{0,12})(?:\.([0-9]{1,2}))?$/D',$amount,$m))
   throw new DomainException('Invalid source decimal spend.');
  $fraction=$m[2]??'';
  if(strlen($fraction)>$scale)throw new DomainException('Source amount has excessive precision.');
  return ((int)$m[1])*(10**$scale)+(int)str_pad($fraction,$scale,'0');
 }
 private static function count(string $value):int {
  if(!preg_match('/^[0-9]{1,15}$/D',$value))throw new DomainException('Invalid Meta metric.');
  return (int)$value;
 }
}
