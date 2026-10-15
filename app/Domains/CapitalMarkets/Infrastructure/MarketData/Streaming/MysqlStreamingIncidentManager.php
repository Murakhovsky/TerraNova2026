<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Infrastructure\MarketData\Streaming;

use DomainException;
use PDO;
use Throwable;

/**
 * Durable, tenant-scoped operational incident ledger. Repeated detections
 * are idempotent per (source, subject, code); acknowledgment is not resolution.
 * No automatic close merely because a broken sensor stopped reporting.
 */
final readonly class MysqlStreamingIncidentManager
{
    public function __construct(private PDO $pdo){}

    /** @param list<array<string,mixed>> $signals
     * @return array{opened:int,reopened:int,active:int}
     */
    public function detect(string $org,array $signals):array
    {
        if($org===''||strlen($org)>190||count($signals)>3000
            ||$this->pdo->inTransaction())throw new DomainException('Invalid incident detection scope.');
        $opened=0;$reopened=0;$active=0;
        $this->pdo->beginTransaction();
        try{
            foreach($signals as $signal){
                $source=$signal['source_id']??null;
                $code=$signal['code']??null;
                $severity=$signal['severity']??null;
                $subject=$signal['instrument_id']??$signal['shard_key']??'';
                if(!is_string($source)||$source===''||strlen($source)>190
                    ||!is_string($subject)||strlen($subject)>190
                    ||!is_string($code)||strlen($code)>100
                    ||preg_match('/^[A-Z0-9_]+$/D',$code)!==1
                    ||!in_array($severity,['CRITICAL','WARNING'],true)){
                    throw new DomainException('Malformed incident signal.');
                }
                $q=$this->pdo->prepare(
                    "INSERT IGNORE INTO tn_capital_market_stream_incidents
                     (organization_id,source_id,subject_id,code,severity,status,
                      first_seen_at,last_seen_at)
                     VALUES (:org,:source,:subject,:code,:severity,'OPEN',
                             UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))");
                $params=['org'=>$org,'source'=>$source,'subject'=>$subject,
                    'code'=>$code,'severity'=>$severity];
                $q->execute($params);
                if($q->rowCount()===1){$opened++;$active++;continue;}
                $update=$this->pdo->prepare(
                    "UPDATE tn_capital_market_stream_incidents
                     SET severity=:severity,occurrences=occurrences+1,
                         last_seen_at=UTC_TIMESTAMP(6),
                         status=CASE WHEN status='RESOLVED' THEN 'OPEN' ELSE status END,
                         first_seen_at=CASE WHEN status='RESOLVED' THEN UTC_TIMESTAMP(6)
                             ELSE first_seen_at END,
                         acknowledged_at=CASE WHEN status='RESOLVED' THEN NULL ELSE acknowledged_at END,
                         acknowledged_by=CASE WHEN status='RESOLVED' THEN NULL ELSE acknowledged_by END,
                         last_notification_at=CASE WHEN status='RESOLVED' THEN NULL ELSE last_notification_at END,
                         resolved_at=CASE WHEN status='RESOLVED' THEN NULL ELSE resolved_at END
                     WHERE organization_id=:org AND source_id=:source
                       AND subject_id=:subject AND code=:code");
                $before=$this->pdo->prepare(
                    'SELECT status FROM tn_capital_market_stream_incidents
                     WHERE organization_id=:org AND source_id=:source
                       AND subject_id=:subject AND code=:code FOR UPDATE');
                $before->execute(array_diff_key($params,['severity'=>true]));
                $was=$before->fetchColumn();
                if($was==='RESOLVED')$reopened++;
                $update->execute($params);
                $active++;
            }
            $this->pdo->commit();
            return ['opened'=>$opened,'reopened'=>$reopened,'active'=>$active];
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function acknowledge(string $org,int $actor,int $id):bool
    {
        if($actor<1||$id<1||$org==='')throw new DomainException('Invalid incident ACK.');
        $q=$this->pdo->prepare(
            "UPDATE tn_capital_market_stream_incidents
             SET status='ACKNOWLEDGED',acknowledged_by=:actor,
                 acknowledged_at=UTC_TIMESTAMP(6)
             WHERE organization_id=:org AND id=:id AND status='OPEN'");
        $q->execute(['org'=>$org,'id'=>$id,'actor'=>$actor]);
        return $q->rowCount()===1;
    }

    /** Resolution requires external proof that the previously detected condition cleared. */
    public function resolve(string $org,int $id,string $source,string $code):bool
    {
        if($org===''||$id<1||$source===''||$code==='')throw new DomainException('Invalid incident resolution.');
        $q=$this->pdo->prepare(
            "UPDATE tn_capital_market_stream_incidents
             SET status='RESOLVED',resolved_at=UTC_TIMESTAMP(6)
             WHERE organization_id=:org AND id=:id AND source_id=:source AND code=:code
               AND status IN ('OPEN','ACKNOWLEDGED')");
        $q->execute(['org'=>$org,'id'=>$id,'source'=>$source,'code'=>$code]);
        return $q->rowCount()===1;
    }

    /** @return list<array<string,mixed>> */
    public function list(string $org,?string $source=null,int $limit=200):array
    {
        if($org===''||strlen($org)>190||$limit<1||$limit>500
            ||($source!==null&&($source===''||strlen($source)>190))){
            throw new DomainException('Invalid incident query scope.');
        }
        $sql='SELECT id,source_id,subject_id,code,severity,status,occurrences,
                     first_seen_at,last_seen_at,acknowledged_at,resolved_at,
                     last_notification_at,notification_attempts
              FROM tn_capital_market_stream_incidents WHERE organization_id=:org';
        $params=['org'=>$org];
        if($source!==null){$sql.=' AND source_id=:source';$params['source']=$source;}
        $sql.=' ORDER BY last_seen_at DESC LIMIT '.(int)$limit;
        $q=$this->pdo->prepare($sql);
        $q->execute($params);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** For notification delivery, always return a scoped persistent id. */
    public function pendingNotifications(string $org,?string $source=null,int $limit=100):array
    {
        if($org===''||$limit<1||$limit>500)throw new DomainException('Invalid notification scope.');
        $params=['org'=>$org];
        $where='';
        if($source!==null){$where=' AND source_id=:source';$params['source']=$source;}
        $q=$this->pdo->prepare(
            "SELECT id,source_id,subject_id,code,severity,status,first_seen_at
             FROM tn_capital_market_stream_incidents
             WHERE organization_id=:org AND status IN ('OPEN','ACKNOWLEDGED')
               AND last_notification_at IS NULL".$where."
             ORDER BY first_seen_at ASC LIMIT ".$limit);
        $q->execute($params);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function notificationQueued(string $org,int $id):void
    {
        if($org===''||$id<1)throw new DomainException('Invalid queued notice.');
        $q=$this->pdo->prepare(
            "UPDATE tn_capital_market_stream_incidents
             SET last_notification_at=COALESCE(last_notification_at,UTC_TIMESTAMP(6)),
                 notification_attempts=notification_attempts+1
             WHERE organization_id=:org AND id=:id AND status IN ('OPEN','ACKNOWLEDGED')");
        $q->execute(['org'=>$org,'id'=>$id]);
    }
}
