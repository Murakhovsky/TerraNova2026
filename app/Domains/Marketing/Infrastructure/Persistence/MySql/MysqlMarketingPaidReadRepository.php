<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Domains\Marketing\Application\Contract\MarketingPaidReadRepository;
use PDO;
use PDOException;
use Throwable;

final readonly class MysqlMarketingPaidReadRepository implements MarketingPaidReadRepository
{
    public function __construct(private PDO $connection){}

    public function registerAccount(string $organizationId,string $credentialRef,array $verified):array
    {
        $account=(string)($verified['ad_account_id']??'');
        if(!preg_match('/^act_[0-9]{1,30}$/D',$account)
            ||!preg_match('/^[A-Z]{3}$/D',(string)($verified['currency']??''))
            ||!in_array((string)($verified['timezone']??''),DateTimeZone::listIdentifiers(),true)
            ||($verified['connection_status']??null)!=='READ_ONLY')throw new DomainException('Unverified Meta account cannot be connected.');
        $pdo=$this->connection;
        try {
            $pdo->beginTransaction();
            $stmt=$pdo->prepare('SELECT organization_id,id FROM marketing_paid_accounts WHERE provider=? AND ad_account_id=? FOR UPDATE');
            $stmt->execute(['meta',$account]);$existing=$stmt->fetch(PDO::FETCH_ASSOC);
            if(is_array($existing)){
                if($existing['organization_id']!==$organizationId)throw new DomainException('Ad Account already belongs to a different organization.');
                $pdo->prepare("UPDATE marketing_paid_accounts SET credential_ref=?,currency=?,timezone=?,capability_scope_json=?,connection_status='READ_ONLY',last_verified_at=UTC_TIMESTAMP(6),revision=revision+1 WHERE organization_id=? AND id=?")
                    ->execute([$credentialRef,$verified['currency'],$verified['timezone'],json_encode($verified['capability_scope'],JSON_THROW_ON_ERROR),$organizationId,$existing['id']]);
                $id=(string)$existing['id'];
            } else {
                $id='mads-'.bin2hex(random_bytes(12));
                $pdo->prepare("INSERT INTO marketing_paid_accounts (organization_id,id,provider,business_id,ad_account_id,currency,timezone,credential_ref,connection_status,capability_scope_json,last_verified_at) VALUES (?,?, 'meta',NULL,?,?,?,?, 'READ_ONLY',?,UTC_TIMESTAMP(6))")
                    ->execute([$organizationId,$id,$account,$verified['currency'],$verified['timezone'],$credentialRef,json_encode($verified['capability_scope'],JSON_THROW_ON_ERROR)]);
            }
            $pdo->commit();
            $row=$this->account($organizationId,$id);
            if($row===null)throw new DomainException('Verified account not found after connection.');
            unset($row['credential_ref']);
            return $row;
        }catch(Throwable $error){
            if($pdo->inTransaction())$pdo->rollBack();
            if($error instanceof PDOException&&$error->getCode()==='23000')throw new DomainException('Ad Account binding already exists.');
            throw $error;
        }
    }

    public function accounts(string $organizationId,int $limit=100):array
    {
        $limit=max(1,min(100,$limit));
        $stmt=$this->connection->prepare('SELECT id,provider,ad_account_id,business_id,currency,timezone,connection_status,capability_scope_json,last_verified_at,last_synced_at,revision,updated_at FROM marketing_paid_accounts WHERE organization_id=? ORDER BY updated_at DESC LIMIT '.$limit);
        $stmt->execute([$organizationId]);
        return array_map(self::accountProjection(...),$stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function account(string $organizationId,string $id):?array
    {
        $stmt=$this->connection->prepare('SELECT * FROM marketing_paid_accounts WHERE organization_id=? AND id=?');
        $stmt->execute([$organizationId,$id]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?self::accountProjection($row):null;
    }

    public function importSnapshot(string $organizationId,string $accountId,string $from,string $to,array $hierarchy,array $insights):array
    {
        $pdo=$this->connection;
        if($pdo->inTransaction())throw new DomainException('Nested snapshot transaction denied.');
        try {
            $pdo->beginTransaction();
            $counts=$this->importHierarchy($organizationId,$accountId,$hierarchy);
            $counts['insights']=$this->importInsights($organizationId,$accountId,'campaign',$from,$to,$insights);
            $pdo->prepare('UPDATE marketing_paid_accounts SET last_synced_at=UTC_TIMESTAMP(6) WHERE organization_id=? AND id=?')
                ->execute([$organizationId,$accountId]);
            $pdo->prepare("INSERT INTO marketing_paid_sync_runs (organization_id,id,account_id,requested_by,state,fetched_count,started_at,finished_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")
                ->execute([$organizationId,'msr-'.bin2hex(random_bytes(12)),$accountId,'manual_manager','SUCCEEDED',array_sum($counts)]);
            $pdo->commit();
            return $counts;
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
    }

    public function hierarchy(string $organizationId,string $accountId):array
    {
        $campaigns=$this->campaigns($organizationId,$accountId,100);
        $adsets=$this->connection->prepare('SELECT a.id,a.paid_campaign_id,a.provider_adset_id,a.name,a.delivery_status,a.updated_at,a.revision FROM marketing_paid_adsets a JOIN marketing_paid_campaigns c ON c.organization_id=a.organization_id AND c.id=a.paid_campaign_id WHERE c.organization_id=? AND c.account_id=? ORDER BY a.updated_at DESC LIMIT 1000');
        $adsets->execute([$organizationId,$accountId]);
        $ads=$this->connection->prepare('SELECT d.id,d.paid_adset_id,d.provider_ad_id,d.name,d.creative_ref,d.delivery_status,d.updated_at,d.revision FROM marketing_paid_ads d JOIN marketing_paid_adsets a ON a.organization_id=d.organization_id AND a.id=d.paid_adset_id JOIN marketing_paid_campaigns c ON c.organization_id=a.organization_id AND c.id=a.paid_campaign_id WHERE c.organization_id=? AND c.account_id=? ORDER BY d.updated_at DESC LIMIT 1000');
        $ads->execute([$organizationId,$accountId]);
        return ['campaigns'=>$campaigns,'adsets'=>$adsets->fetchAll(PDO::FETCH_ASSOC),'ads'=>$ads->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function importHierarchy(string $organizationId,string $accountId,array $hierarchy):array
    {
        foreach(['campaigns','adsets','ads'] as $key){
            if(!isset($hierarchy[$key])||!is_array($hierarchy[$key])||!array_is_list($hierarchy[$key])||count($hierarchy[$key])>1000){
                throw new DomainException('Incomplete provider hierarchy; no records imported.');
            }
        }
        $pdo=$this->connection;
        $ownsTransaction=!$pdo->inTransaction();
        $counts=['campaigns'=>0,'adsets'=>0,'ads'=>0];
        try {
            if($ownsTransaction)$pdo->beginTransaction();
            $account=$this->lockedAccount($organizationId,$accountId);
            if($account['connection_status']!=='READ_ONLY')throw new DomainException('Connection is not read-only verified.');
            $campaignMap=[];$adsetMap=[];
            foreach($hierarchy['campaigns'] as $row){
                $providerId=self::externalId($row);
                $hash=self::fingerprint($row);
                $id=$this->resolveId('marketing_paid_campaigns','provider_campaign_id',$organizationId,$accountId,$providerId);
                $name=self::label($row);$status=self::status($row);
                $objective=substr((string)($row['objective']??'unknown'),0,100);
                $updated=self::providerTime($row['updated_time']??null);
                if($id===null){
                    $id='mpc-'.bin2hex(random_bytes(12));
                    $pdo->prepare("INSERT INTO marketing_paid_campaigns (organization_id,id,account_id,provider_campaign_id,name,objective,delivery_status,management_mode,source_hash,source_updated_at) VALUES (?,?,?,?,?,?,?,'EXTERNALLY_MANAGED',?,?)")
                        ->execute([$organizationId,$id,$accountId,$providerId,$name,$objective,$status,$hash,$updated]);
                }else{
                    $pdo->prepare('UPDATE marketing_paid_campaigns SET name=?,objective=?,delivery_status=?,source_updated_at=?,revision=IF(source_hash<>?,revision+1,revision),source_hash=? WHERE organization_id=? AND id=?')
                        ->execute([$name,$objective,$status,$updated,$hash,$hash,$organizationId,$id]);
                }
                $campaignMap[$providerId]=$id;$counts['campaigns']++;
            }
            foreach($hierarchy['adsets'] as $row){
                $providerId=self::externalId($row);
                $providerParent=self::externalId(['id'=>$row['campaign_id']??null]);
                $parent=$campaignMap[$providerParent]??$this->lookupCampaign($organizationId,$accountId,$providerParent);
                if($parent===null)throw new DomainException('Orphan AdSet; hierarchy not imported.');
                $hash=self::fingerprint($row);$name=self::label($row);$status=self::status($row);
                $stmt=$pdo->prepare('SELECT id FROM marketing_paid_adsets WHERE organization_id=? AND paid_campaign_id=? AND provider_adset_id=?');
                $stmt->execute([$organizationId,$parent,$providerId]);$id=$stmt->fetchColumn();
                if($id===false){
                    $id='mas-'.bin2hex(random_bytes(12));
                    $pdo->prepare('INSERT INTO marketing_paid_adsets (organization_id,id,paid_campaign_id,provider_adset_id,name,delivery_status,source_hash) VALUES (?,?,?,?,?,?,?)')
                        ->execute([$organizationId,$id,$parent,$providerId,$name,$status,$hash]);
                }else{
                    $pdo->prepare('UPDATE marketing_paid_adsets SET name=?,delivery_status=?,revision=IF(source_hash<>?,revision+1,revision),source_hash=? WHERE organization_id=? AND id=?')
                        ->execute([$name,$status,$hash,$hash,$organizationId,$id]);
                }
                $adsetMap[$providerId]=(string)$id;$counts['adsets']++;
            }
            foreach($hierarchy['ads'] as $row){
                $providerId=self::externalId($row);
                $providerParent=self::externalId(['id'=>$row['adset_id']??null]);
                $parent=$adsetMap[$providerParent]??$this->lookupAdset($organizationId,$accountId,$providerParent);
                if($parent===null)throw new DomainException('Orphan Ad; hierarchy not imported.');
                $hash=self::fingerprint($row);$name=self::label($row);$status=self::status($row);
                $creative=$row['creative']['id']??null;
                $creative=is_string($creative)&&preg_match('/^[0-9]{1,100}$/D',$creative)?$creative:null;
                $stmt=$pdo->prepare('SELECT id FROM marketing_paid_ads WHERE organization_id=? AND paid_adset_id=? AND provider_ad_id=?');
                $stmt->execute([$organizationId,$parent,$providerId]);$id=$stmt->fetchColumn();
                if($id===false){
                    $id='mad-'.bin2hex(random_bytes(12));
                    $pdo->prepare('INSERT INTO marketing_paid_ads (organization_id,id,paid_adset_id,provider_ad_id,creative_ref,name,delivery_status,source_hash) VALUES (?,?,?,?,?,?,?,?)')
                        ->execute([$organizationId,$id,$parent,$providerId,$creative,$name,$status,$hash]);
                }else{
                    $pdo->prepare('UPDATE marketing_paid_ads SET creative_ref=?,name=?,delivery_status=?,revision=IF(source_hash<>?,revision+1,revision),source_hash=? WHERE organization_id=? AND id=?')
                        ->execute([$creative,$name,$status,$hash,$hash,$organizationId,$id]);
                }
                $counts['ads']++;
            }
            if($ownsTransaction)$pdo->commit();
            return $counts;
        }catch(Throwable $e){
            if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
    }

    public function importInsights(string $organizationId,string $accountId,string $grain,string $from,string $to,array $rows):int
    {
        if(!in_array($grain,['account','campaign','adset','ad'],true)||count($rows)>1000)throw new DomainException('Invalid insight scope.');
        $pdo=$this->connection;
        $ownsTransaction=!$pdo->inTransaction();
        try {
            if($ownsTransaction)$pdo->beginTransaction();
            $account=$this->lockedAccount($organizationId,$accountId);
            $attribution=hash('sha256','meta-default-adset-attribution-v1');
            foreach($rows as $row){
                if(!is_array($row)||($row['date_start']??null)!==$from||($row['date_stop']??null)!==$to)
                    throw new DomainException('Meta insights window mismatch.');
                $field=['account'=>'account_id','campaign'=>'campaign_id','adset'=>'adset_id','ad'=>'ad_id'][$grain];
                $external=(string)($row[$field]??'');
                if(!preg_match('/^[0-9]{1,100}$/D',$external))throw new DomainException('Invalid insights external scope.');
                $spend=(string)($row['spend']??'0');
                if(!preg_match('/^[0-9]{1,14}(?:\.[0-9]{1,6})?$/D',$spend))
                    throw new DomainException('Meta spend is not a valid nonnegative decimal amount.');
                $hash=self::fingerprint($row);$id='mis-'.bin2hex(random_bytes(12));
                $pdo->prepare("INSERT INTO marketing_paid_insight_snapshots (organization_id,id,account_id,provider,grain,external_id,window_start,window_end,timezone,currency,attribution_hash,source_hash,source_status,spend_decimal,metrics_json) VALUES (?,?,?,'meta',?,?,?,?,?,?,?,?,'PROVISIONAL',?,?) ON DUPLICATE KEY UPDATE revision=IF(source_hash<>VALUES(source_hash),revision+1,revision),source_status=IF(source_hash<>VALUES(source_hash),'REVISED',source_status),source_hash=VALUES(source_hash),spend_decimal=VALUES(spend_decimal),metrics_json=VALUES(metrics_json),fetched_at=UTC_TIMESTAMP(6)")
                    ->execute([$organizationId,$id,$accountId,$grain,$external,$from,$to,$account['timezone'],$account['currency'],$attribution,$hash,$spend,json_encode($row,JSON_THROW_ON_ERROR)]);
            }
            if($ownsTransaction)$pdo->commit();
            return count($rows);
        }catch(Throwable $e){
            if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
    }

    public function insights(string $organizationId,string $accountId,string $grain,string $from,string $to):array
    {
        $stmt=$this->connection->prepare('SELECT id,grain,external_id,window_start,window_end,timezone,currency,source_status,spend_decimal,metrics_json,fetched_at,revision FROM marketing_paid_insight_snapshots WHERE organization_id=? AND account_id=? AND grain=? AND window_start=? AND window_end=? ORDER BY external_id ASC LIMIT 1000');
        $stmt->execute([$organizationId,$accountId,$grain,$from,$to]);
        return array_map(static function(array $row):array {
            $row['metrics']=json_decode($row['metrics_json'],true,32,JSON_THROW_ON_ERROR);
            unset($row['metrics_json']);
            $row['revision']=(int)$row['revision'];
            return $row;
        },$stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function campaigns(string $organizationId,string $accountId,int $limit=100):array
    {
        $limit=max(1,min($limit,100));
        $stmt=$this->connection->prepare('SELECT id,provider_campaign_id,name,objective,delivery_status,management_mode,source_updated_at,updated_at,revision FROM marketing_paid_campaigns WHERE organization_id=? AND account_id=? ORDER BY updated_at DESC LIMIT '.$limit);
        $stmt->execute([$organizationId,$accountId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function lockedAccount(string $org,string $id):array
    {
        $stmt=$this->connection->prepare('SELECT * FROM marketing_paid_accounts WHERE organization_id=? AND id=? FOR UPDATE');
        $stmt->execute([$org,$id]);$account=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($account))throw new DomainException('Marketing Ad Account not found.');
        return $account;
    }
    private function resolveId(string $table,string $externalColumn,string $org,string $account,string $external):?string
    {
        // Only domain-owned hardcoded table/column identifiers are used.
        $stmt=$this->connection->prepare("SELECT id FROM $table WHERE organization_id=? AND account_id=? AND $externalColumn=?");
        $stmt->execute([$org,$account,$external]);$id=$stmt->fetchColumn();
        return $id===false?null:(string)$id;
    }
    private function lookupCampaign(string $org,string $account,string $external):?string
    {
        return $this->resolveId('marketing_paid_campaigns','provider_campaign_id',$org,$account,$external);
    }
    private function lookupAdset(string $org,string $account,string $external):?string
    {
        $stmt=$this->connection->prepare('SELECT a.id FROM marketing_paid_adsets a JOIN marketing_paid_campaigns c ON c.organization_id=a.organization_id AND c.id=a.paid_campaign_id WHERE c.organization_id=? AND c.account_id=? AND a.provider_adset_id=?');
        $stmt->execute([$org,$account,$external]);$id=$stmt->fetchColumn();
        return $id===false?null:(string)$id;
    }
    private static function externalId(array $row):string
    {
        $id=$row['id']??null;
        if(!is_string($id)||!preg_match('/^[0-9]{1,100}$/D',$id))throw new DomainException('Meta provider ID invalid.');
        return $id;
    }
    private static function label(array $row):string
    {
        $value=trim((string)($row['name']??''));
        return $value===''?'(unnamed)':mb_substr($value,0,190);
    }
    private static function status(array $row):string
    {
        $status=(string)($row['effective_status']??$row['status']??'UNKNOWN');
        if(!preg_match('/^[A-Z_]{2,50}$/D',$status))throw new DomainException('Invalid provider effective state.');
        return $status;
    }
    private static function providerTime(mixed $value):?string
    {
        if(!is_string($value)||$value==='')return null;
        try {return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');}
        catch(Throwable){throw new DomainException('Invalid provider updated timestamp.');}
    }
    private static function fingerprint(array $row):string
    {
        return hash('sha256',json_encode($row,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function accountProjection(array $row):array
    {
        if(isset($row['capability_scope_json'])){
            $row['capability_scope']=json_decode((string)$row['capability_scope_json'],true,32,JSON_THROW_ON_ERROR);
            unset($row['capability_scope_json']);
        }
        $row['revision']=(int)$row['revision'];
        return $row;
    }
}
