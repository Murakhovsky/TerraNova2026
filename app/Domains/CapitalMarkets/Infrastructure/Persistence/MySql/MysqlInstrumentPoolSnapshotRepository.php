<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;
use DomainException;
use PDO;
use RuntimeException;
/** Durable, tenant-scoped discovery evidence, not an authoritative instrument registry. */
final readonly class MysqlInstrumentPoolSnapshotRepository
{
    public function __construct(private PDO $pdo){}
    public function reserveScan(string $organizationId,string $provider,string $venueId,int $cooldownSeconds=90):bool
    {
        $this->scope($organizationId,$provider,$venueId);
        if($cooldownSeconds<60||$cooldownSeconds>86400||$this->pdo->inTransaction()){
            throw new DomainException('Instrument Pool scan requires its own bounded reservation.');
        }
        $scope=['org'=>$organizationId,'provider'=>$provider,'venue'=>$venueId];
        $this->pdo->prepare(
            "INSERT IGNORE INTO tn_capital_market_instrument_pool_scan_gates
             (organization_id,provider,venue_id,last_attempt_at)
             VALUES (:org,:provider,:venue,'1970-01-01 00:00:00')"
        )->execute($scope);
        $update=$this->pdo->prepare(
            'UPDATE tn_capital_market_instrument_pool_scan_gates
             SET last_attempt_at=UTC_TIMESTAMP(6)
             WHERE organization_id=:org AND provider=:provider AND venue_id=:venue
               AND TIMESTAMPDIFF(SECOND,last_attempt_at,UTC_TIMESTAMP(6))>=:cooldown'
        );
        $update->execute([...$scope,'cooldown'=>$cooldownSeconds]);
        return $update->rowCount()===1;
    }

    /** @param array<string,mixed> $snapshot */
    public function record(string $organizationId,int $actorId,array $snapshot):int
    {
        $provider=$snapshot['provider']??null;
        $venueId=$snapshot['venue_id']??null;
        if(!is_string($provider)||!is_string($venueId))throw new DomainException('Missing pool snapshot identity.');
        $this->scope($organizationId,$provider,$venueId);
        $health=$snapshot['health']??null;
        $count=$snapshot['provider_count']??null;
        $hash=$snapshot['evidence_sha256']??null;
        if($actorId<1||!in_array($health,['AVAILABLE','UNAVAILABLE'],true)
            ||($snapshot['dry_run']??null)!==true
            ||($snapshot['executable']??null)!==false
            ||!is_array($snapshot['rows']??null)||count($snapshot['rows'])>500
            ||!is_int($snapshot['mapping_count']??null)||$snapshot['mapping_count']<0){
            throw new DomainException('Unsafe Instrument Pool discovery snapshot.');
        }
        if($health==='UNAVAILABLE'){
            if($count!==null||$hash!==null||$snapshot['rows']!==[]||($snapshot['counts']??null)!==null){
                throw new DomainException('Unavailable provider cannot establish missing/delisted symbols.');
            }
        }elseif(!is_int($count)||$count<1||$count>20000
            ||!is_string($hash)||preg_match('/^[a-f0-9]{64}$/D',$hash)!==1
            ||!is_array($snapshot['counts']??null)){
            throw new DomainException('Available provider requires complete evidence and counts.');
        }
        $json=json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if(strlen($json)>1048576)throw new DomainException('Instrument Pool snapshot exceeds 1 MiB.');
        $statement=$this->pdo->prepare(
            'INSERT INTO tn_capital_market_instrument_pool_snapshots
             (organization_id,provider,venue_id,actor_id,health,observed_at,
              evidence_sha256,provider_count,mapping_count,results_json)
             VALUES (:org,:provider,:venue,:actor,:health,UTC_TIMESTAMP(6),
              :hash,:count,:mappings,:results)'
        );
        $statement->execute([
            'org'=>$organizationId,'provider'=>$provider,'venue'=>$venueId,'actor'=>$actorId,
            'health'=>$health,'hash'=>$hash,'count'=>$count,
            'mappings'=>$snapshot['mapping_count'],'results'=>$json,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function latest(string $organizationId,string $provider,string $venueId):?array
    {
        $this->scope($organizationId,$provider,$venueId);
        $stmt=$this->pdo->prepare(
            'SELECT id,actor_id,observed_at,results_json
             FROM tn_capital_market_instrument_pool_snapshots
             WHERE organization_id=:org AND provider=:provider AND venue_id=:venue
             ORDER BY observed_at DESC,id DESC LIMIT 1'
        );
        $stmt->execute(['org'=>$organizationId,'provider'=>$provider,'venue'=>$venueId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))return null;
        $result=json_decode((string)$row['results_json'],true,64,JSON_THROW_ON_ERROR);
        if(!is_array($result)||array_is_list($result))throw new RuntimeException('Corrupt Instrument Pool evidence.');
        $result['snapshot_id']=(int)$row['id'];
        $result['stored_at_utc']=(string)$row['observed_at'];
        $result['actor_id']=(int)$row['actor_id'];
        return $result;
    }

    /** @return list<array<string,mixed>> */
    public function history(string $organizationId,string $provider,string $venueId,int $limit=5):array
    {
        $this->scope($organizationId,$provider,$venueId);
        if($limit<1||$limit>10)throw new DomainException('Instrument Pool history limit must be 1..10.');
        $stmt=$this->pdo->prepare(
            'SELECT id,observed_at,results_json FROM tn_capital_market_instrument_pool_snapshots
             WHERE organization_id=:org AND provider=:provider AND venue_id=:venue
             ORDER BY observed_at DESC,id DESC LIMIT '.$limit
        );
        $stmt->execute(['org'=>$organizationId,'provider'=>$provider,'venue'=>$venueId]);
        $out=[];
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
            $value=json_decode((string)$row['results_json'],true,64,JSON_THROW_ON_ERROR);
            if(!is_array($value)||array_is_list($value)){
                throw new RuntimeException('Corrupted historical Instrument Pool evidence.');
            }
            $value['snapshot_id']=(int)$row['id'];
            $value['stored_at_utc']=(string)$row['observed_at'];
            $out[]=$value;
        }
        return $out;
    }

    private function scope(string $org,string $provider,string $venue):void
    {
        foreach([$org,$venue] as $identity){
            if($identity===''||trim($identity)!==$identity||strlen($identity)>190){
                throw new DomainException('Invalid Instrument Pool tenant or venue.');
            }
        }
        if(!in_array($provider,['BINANCE','BYBIT','KRAKEN'],true)){
            throw new DomainException('Unsupported Instrument Pool provider.');
        }
    }
}
