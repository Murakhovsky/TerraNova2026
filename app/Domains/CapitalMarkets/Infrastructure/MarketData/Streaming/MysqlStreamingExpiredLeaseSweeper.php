<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Streaming;

use DomainException;
use Domains\CapitalMarkets\Application\Contract\StreamingTrustInvalidatorInterface;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Independent dead-worker recovery across the currently supported BBO pilots.
 *
 * Scope is deliberately limited to exact known shard names. Every candidate
 * is rechecked after taking the SOURCE guard and its shard row lock. A
 * replacement worker or renewed lease is never invalidated by this scanner.
 *
 * The stream invalidator is currently source-wide, not per-channel; do not
 * expand this allowlist to per-channel shards until invalidation is scoped.
 */
final readonly class MysqlStreamingExpiredLeaseSweeper
{
    /** @var list<string> */
    public const PILOT_SHARDS=[
        'binance-spot-single-bbo',
        'bybit-spot-single-bbo',
        'kraken-spot-single-bbo',
        'binance-usdm-single-bbo',
        'bybit-linear-single-bbo',
        'massive-stocks-single-bbo',
    ];

    public function __construct(
        private PDO $pdo,
        private StreamingTrustInvalidatorInterface $invalidator,
    ){}

    /**
     * @return array{examined:int,invalidated:int,skipped:int,failed:int}
     */
    public function sweepBatch(int $limit=100,?string $organizationId=null):array
    {
        if($limit<1||$limit>500){
            throw new DomainException('Streaming sweep batch limit must be 1..500.');
        }
        if($organizationId!==null){
            $this->validateIdentity($organizationId);
        }
        if($this->pdo->inTransaction()){
            throw new DomainException('Streaming sweep cannot run in another transaction.');
        }

        $placeholders=[];
        $params=[];
        foreach(self::PILOT_SHARDS as $i=>$shard){
            $name='shard'.$i;
            $placeholders[]=':'.$name;
            $params[$name]=$shard;
        }
        $tenantFilter='';
        if($organizationId!==null){
            $tenantFilter=' AND organization_id=:org';
            $params['org']=$organizationId;
        }
        $sql="SELECT organization_id,source_id,shard_key,owner_id,fencing_token
              FROM tn_capital_market_stream_worker_leases
              WHERE owner_id<>'' AND expires_at<=UTC_TIMESTAMP(6)
                AND shard_key IN (".implode(',',$placeholders).")".$tenantFilter."
              ORDER BY expires_at ASC,organization_id,source_id,shard_key LIMIT ".$limit;
        $statement=$this->pdo->prepare($sql);
        $statement->execute($params);
        $candidates=$statement->fetchAll(PDO::FETCH_ASSOC);
        $counts=['examined'=>0,'invalidated'=>0,'skipped'=>0,'failed'=>0];
        foreach($candidates as $candidate){
            $counts['examined']++;
            try{
                $result=$this->recover($candidate);
                if($result==='SKIPPED')$counts['skipped']++;
                else $counts['invalidated']++;
            }catch(Throwable){
                // An invalidation failure keeps the emergency owner's lease
                // until expiry, so the next scheduled sweep can retry.
                $counts['failed']++;
            }
        }
        return $counts;
    }

    /** @param array<string,mixed> $observed */
    private function recover(array $observed):string
    {
        $org=(string)$observed['organization_id'];
        $source=(string)$observed['source_id'];
        $shard=(string)$observed['shard_key'];
        $owner=(string)$observed['owner_id'];
        $token=(string)$observed['fencing_token'];
        $this->validateIdentity($org);
        $this->validateIdentity($source);
        if(!in_array($shard,self::PILOT_SHARDS,true)||$owner===''||!ctype_digit($token)){
            throw new DomainException('Unknown streaming lease candidate.');
        }
        $newOwner='cm-lease-sweep-'.bin2hex(random_bytes(12));
        $args=['org'=>$org,'source'=>$source,'shard'=>$shard];
        $this->pdo->beginTransaction();
        try{
            // Every writer / lease acquirer uses source guard BEFORE shard.
            MysqlMarketSourceWriterGate::lockSource($this->pdo,$org,$source);
            $lock=$this->pdo->prepare(
                "SELECT owner_id,fencing_token
                 FROM tn_capital_market_stream_worker_leases
                 WHERE organization_id=:org AND source_id=:source AND shard_key=:shard
                   AND owner_id<>'' AND expires_at<=UTC_TIMESTAMP(6) FOR UPDATE"
            );
            $lock->execute($args);
            $actual=$lock->fetch(PDO::FETCH_ASSOC);
            if(!is_array($actual)||$actual['owner_id']!==$owner
                ||(string)$actual['fencing_token']!==$token){
                $this->pdo->commit();
                return 'SKIPPED';
            }
            $old=(int)$actual['fencing_token'];
            if($old<1||$old===PHP_INT_MAX||$token!==(string)$old){
                throw new DomainException('Invalid or overflowed worker fencing token.');
            }
            $next=$old+1;
            $takeover=$this->pdo->prepare(
                'UPDATE tn_capital_market_stream_worker_leases
                 SET owner_id=:new_owner,fencing_token=:new_token,
                     expires_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 30 SECOND),
                     updated_at=UTC_TIMESTAMP(6)
                 WHERE organization_id=:org AND source_id=:source AND shard_key=:shard
                   AND owner_id=:old_owner AND fencing_token=:old_token
                   AND expires_at<=UTC_TIMESTAMP(6)'
            );
            $takeover->execute([
                ...$args,'new_owner'=>$newOwner,'new_token'=>$next,
                'old_owner'=>$owner,'old_token'=>$old,
            ]);
            if($takeover->rowCount()!==1){
                throw new RuntimeException('Failed to claim expired worker lease.');
            }
            $this->pdo->commit();
        }catch(Throwable $error){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $error;
        }

        // The lease was claimed and committed. If invalidation throws, do
        // not release it: another sweep retries after this lease expires.
        $this->invalidator->invalidate(
            $org,$source,$shard,$newOwner,$next,'LEASE_EXPIRED',
        );
        $release=$this->pdo->prepare(
            "UPDATE tn_capital_market_stream_worker_leases
             SET owner_id='',expires_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6)
             WHERE organization_id=:org AND source_id=:source AND shard_key=:shard
               AND owner_id=:owner AND fencing_token=:token"
        );
        $release->execute([...$args,'owner'=>$newOwner,'token'=>$next]);
        if($release->rowCount()!==1){
            throw new RuntimeException('Sweep lease was superseded before release.');
        }
        return 'INVALIDATED';
    }

    private function validateIdentity(string $id):void
    {
        if($id===''||trim($id)!==$id||strlen($id)>190){
            throw new DomainException('Invalid streaming lease tenant or source identity.');
        }
    }
}
