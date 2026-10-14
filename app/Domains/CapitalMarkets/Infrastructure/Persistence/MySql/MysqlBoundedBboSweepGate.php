<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;

use PDO;
use RuntimeException;
use Throwable;

/** Per-source fenced advisory lock and atomic inter-run cooldown. */
final readonly class MysqlBoundedBboSweepGate
{
    public function __construct(private PDO $connection) {}

    /** Recent tenant-scoped REST poll outcomes for operator monitoring. */
    public function recent(string $organizationId,int $limit=20): array
    {
        if ($organizationId==='' || strlen($organizationId)>190 || $limit<1 || $limit>100) {
            throw new RuntimeException('Invalid BBO health lookup.');
        }
        $statement=$this->connection->prepare(
            'SELECT source_id,last_attempt_at,last_completed_at,last_status
             FROM tn_capital_market_bbo_poll_gates
             WHERE organization_id=:org ORDER BY last_attempt_at DESC LIMIT '.$limit
        );
        $statement->execute(['org'=>$organizationId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{state:string,status:?string} */
    public function run(string $organizationId,string $sourceId,int $cooldownSeconds,callable $poll): array
    {
        if ($organizationId==='' || strlen($organizationId)>190 || $sourceId===''
            || strlen($sourceId)>190 || $cooldownSeconds<300 || $cooldownSeconds>86400) {
            throw new RuntimeException('Invalid BBO poll lease request.');
        }
        $lock='cm-bbo-'.substr(hash('sha256',$organizationId.'|'.$sourceId),0,50);
        $take=$this->connection->prepare('SELECT GET_LOCK(:lock_name,0)');
        $take->execute(['lock_name'=>$lock]);
        if ((int)$take->fetchColumn()!==1) return ['state'=>'LEASE_BUSY','status'=>null];

        try {
            $this->connection->prepare(
                'INSERT IGNORE INTO tn_capital_market_bbo_poll_gates
                 (organization_id,source_id,last_attempt_at,last_status)
                 VALUES (:org,:source,\'1970-01-01 00:00:00\',\'NOT_STARTED\')'
            )->execute(['org'=>$organizationId,'source'=>$sourceId]);

            $claim=$this->connection->prepare(
                'UPDATE tn_capital_market_bbo_poll_gates
                 SET last_attempt_at=UTC_TIMESTAMP(6),last_status=\'RUNNING\'
                 WHERE organization_id=:org AND source_id=:source
                   AND TIMESTAMPDIFF(SECOND,last_attempt_at,UTC_TIMESTAMP(6))>=:cooldown'
            );
            $claim->execute(['org'=>$organizationId,'source'=>$sourceId,'cooldown'=>$cooldownSeconds]);
            if ($claim->rowCount()!==1) return ['state'=>'COOLDOWN','status'=>null];

            $status='FAILED';
            try {
                $status=$poll();
                if (!is_string($status) || !in_array($status,
                    ['OK','DEGRADED','FAILED','SOURCE_DISABLED','PROVIDER_DISABLED'],true)) {
                    throw new RuntimeException('Unexpected MarketSourcePollingService status.');
                }
                return ['state'=>'POLLED','status'=>$status];
            } catch (Throwable $e) {
                $status='FAILED';
                throw $e;
            } finally {
                $this->connection->prepare(
                    'UPDATE tn_capital_market_bbo_poll_gates
                     SET last_completed_at=UTC_TIMESTAMP(6),last_status=:status
                     WHERE organization_id=:org AND source_id=:source'
                )->execute(['org'=>$organizationId,'source'=>$sourceId,'status'=>$status]);
            }
        } finally {
            $this->connection->prepare('SELECT RELEASE_LOCK(:lock_name)')
                ->execute(['lock_name'=>$lock]);
        }
    }
}
