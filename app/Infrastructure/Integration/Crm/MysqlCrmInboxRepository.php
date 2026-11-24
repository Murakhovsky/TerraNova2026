<?php
declare(strict_types=1);

namespace Infrastructure\Integration\Crm;

use DomainException;
use Domains\Sales\Application\Contract\CrmInboxPendingRepositoryInterface;
use Domains\Sales\Application\Contract\CrmInboxRecoveryRepositoryInterface;
use Domains\Sales\Application\Contract\CrmInboxRepositoryInterface;
use Domains\Sales\Application\DTO\CrmInboxItem;
use PDO;
use RuntimeException;
use Throwable;

final readonly class MysqlCrmInboxRepository implements CrmInboxRepositoryInterface, CrmInboxPendingRepositoryInterface, CrmInboxRecoveryRepositoryInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function receive(
        string $organizationId,
        string $provider,
        string $externalEventId,
        string $eventType,
        array $payload,
        string $correlationId,
    ): string {
        if (!$this->connection->inTransaction()) throw new DomainException('CRM admission requires a transaction.');
        $id = bin2hex(random_bytes(16));
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $payloadHash = hash('sha256', $json);

        $statement = $this->connection->prepare(
            'INSERT IGNORE INTO cos_crm_inbox '
            . '(id, organization_id, provider, external_event_id, event_type, payload, payload_hash, signature_verified, '
            . "status, available_at, correlation_id) VALUES (:id, :organization_id, :provider, :external_event_id, "
            . ":event_type, :payload, :payload_hash, 1, 'RECEIVED', NOW(6), :correlation_id)"
        );
        $statement->execute([
            'id' => $id,
            'organization_id' => $organizationId,
            'provider' => $provider,
            'external_event_id' => $externalEventId,
            'event_type' => $eventType,
            'payload' => $json,
            'payload_hash' => $payloadHash,
            'correlation_id' => $correlationId,
        ]);

        if ($statement->rowCount() === 1) {
            return $id;
        }

        $existing = $this->connection->prepare(
            'SELECT id,event_type,payload_hash FROM cos_crm_inbox WHERE organization_id = :organization_id '
            . 'AND provider = :provider AND external_event_id = :external_event_id LIMIT 1 FOR UPDATE'
        );
        $existing->execute([
            'organization_id' => $organizationId,
            'provider' => $provider,
            'external_event_id' => $externalEventId,
        ]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new RuntimeException('Unable to persist CRM inbox item.');
        }

        if (!hash_equals((string) $row['payload_hash'], $payloadHash)
            || !hash_equals((string) $row['event_type'], $eventType)
        ) {
            throw new DomainException('CRM external event id was reused with a different payload.');
        }

        return (string) $row['id'];
    }

    public function claim(string $organizationId, string $id, string $workerId): ?CrmInboxItem
    {
        if ($this->connection->inTransaction()) throw new DomainException('CRM claim must commit before processing.');
        if (trim($workerId)==='' || strlen($workerId)>100) throw new DomainException('Invalid CRM worker.');
        $dead = $this->connection->prepare("UPDATE cos_crm_inbox SET status='DEAD',locked_at=NULL,locked_by=NULL,last_error='CRM attempt budget exhausted' WHERE id=:id AND organization_id=:organization_id AND attempts>=10 AND (status IN ('RECEIVED','FAILED') OR (status='PROCESSING' AND (locked_at IS NULL OR locked_at<=DATE_SUB(NOW(6), INTERVAL 300 SECOND))))");
        $dead->execute(['id'=>$id,'organization_id'=>$organizationId]);
        $token=substr($workerId,0,60).':'.bin2hex(random_bytes(16));
        $statement=$this->connection->prepare("UPDATE cos_crm_inbox SET status='PROCESSING',attempts=attempts+1,locked_at=NOW(6),locked_by=:worker WHERE id=:id AND organization_id=:organization_id AND signature_verified=1 AND attempts<10 AND ((status IN ('RECEIVED','FAILED') AND available_at<=NOW(6)) OR (status='PROCESSING' AND (locked_at IS NULL OR locked_at<=DATE_SUB(NOW(6), INTERVAL 300 SECOND))))");
        $statement->execute(['worker'=>$token,'id'=>$id,'organization_id'=>$organizationId]);
        if ($statement->rowCount()!==1) return null;
        $read=$this->connection->prepare("SELECT * FROM cos_crm_inbox WHERE id=:id AND organization_id=:organization_id AND status='PROCESSING' AND locked_by=:worker LIMIT 1");
        $read->execute(['id'=>$id,'organization_id'=>$organizationId,'worker'=>$token]);
        $row=$read->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        return new CrmInboxItem((string)$row['id'],(string)$row['organization_id'],(string)$row['provider'],(string)$row['external_event_id'],(string)$row['event_type'],json_decode((string)$row['payload'],true,flags:JSON_THROW_ON_ERROR),(int)$row['attempts'],(string)$row['correlation_id'],$token);
    }

    public function lockClaim(CrmInboxItem $item): bool
    {
        if (!$this->connection->inTransaction()) throw new DomainException('CRM processing requires a transaction.');
        if ($item->claimToken==='') return false;
        // Hold the row lock throughout business writes, outbox and completion.
        $statement=$this->connection->prepare("SELECT 1 FROM cos_crm_inbox WHERE id=:id AND organization_id=:organization_id AND status='PROCESSING' AND attempts=:attempts AND locked_by=:token AND signature_verified=1 AND locked_at>DATE_SUB(NOW(6), INTERVAL 300 SECOND) LIMIT 1 FOR UPDATE");
        $statement->execute($this->claimParameters($item));
        return $statement->fetchColumn()!==false;
    }

    public function complete(CrmInboxItem $item): void
    {
        if (!$this->connection->inTransaction()) throw new DomainException('CRM completion requires a transaction.');
        $statement=$this->connection->prepare("UPDATE cos_crm_inbox SET status='COMPLETED',processed_at=NOW(6),locked_at=NULL,locked_by=NULL,last_error=NULL WHERE id=:id AND organization_id=:organization_id AND status='PROCESSING' AND attempts=:attempts AND locked_by=:token");
        $statement->execute($this->claimParameters($item));
        if ($statement->rowCount()!==1) throw new DomainException('CRM processing claim was lost.');
    }

    public function fail(CrmInboxItem $item, Throwable $error): void
    {
        $statement=$this->connection->prepare("UPDATE cos_crm_inbox SET status=:status,available_at=DATE_ADD(NOW(6), INTERVAL 30 SECOND),locked_at=NULL,locked_by=NULL,last_error=:error WHERE id=:id AND organization_id=:organization_id AND status='PROCESSING' AND attempts=:attempts AND locked_by=:token");
        $statement->execute($this->claimParameters($item)+['status'=>$item->attempts>=10?'DEAD':'FAILED','error'=>'CRM processing failed: '.$error::class]);
    }

    public function status(string $organizationId,string $id,bool $lock=false): ?array
    {
        if ($lock && !$this->connection->inTransaction()) throw new DomainException('CRM observation lock requires a transaction.');
        $statement=$this->connection->prepare('SELECT id,organization_id,provider,event_type,status,attempts,signature_verified,available_at,processed_at,updated_at FROM cos_crm_inbox WHERE organization_id=:org AND id=:id LIMIT 1'.($lock?' FOR UPDATE':''));
        $statement->execute(['org'=>$organizationId,'id'=>$id]);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        $row['attempts']=(int)$row['attempts'];
        $row['signature_verified']=(int)$row['signature_verified']===1;
        $row['revision']=hash('sha256',json_encode([$row['organization_id'],$row['id'],$row['status'],$row['attempts'],$row['updated_at']],JSON_THROW_ON_ERROR));
        unset($row['updated_at']);
        return $row;
    }

    public function requeueDead(string $organizationId,string $id,string $revision): bool
    {
        $row=$this->status($organizationId,$id,true);
        if ($row===null || $row['status']!=='DEAD' || !$row['signature_verified'] || !hash_equals($row['revision'],$revision)) return false;
        $statement=$this->connection->prepare("UPDATE cos_crm_inbox SET status='RECEIVED',attempts=0,available_at=NOW(6),locked_at=NULL,locked_by=NULL,processed_at=NULL,last_error=NULL WHERE organization_id=:org AND id=:id AND status='DEAD' AND signature_verified=1");
        $statement->execute(['org'=>$organizationId,'id'=>$id]);
        return $statement->rowCount()===1;
    }

    private function claimParameters(CrmInboxItem $item): array
    {
        return ['id'=>$item->id,'organization_id'=>$item->organizationId,'attempts'=>$item->attempts,'token'=>$item->claimToken];
    }

    /** @return array<int,array{organization_id:string,id:string,correlation_id:string}> */
    public function pending(int $limit = 100): array
    {
        $statement = $this->connection->prepare(
            "SELECT organization_id,id,correlation_id FROM cos_crm_inbox "
            . "WHERE signature_verified=1 AND ((status IN ('RECEIVED','FAILED') AND available_at<=NOW(6)) OR (status='PROCESSING' AND (locked_at IS NULL OR locked_at<=DATE_SUB(NOW(6), INTERVAL 300 SECOND)))) "
            . 'ORDER BY available_at,id LIMIT :limit'
        );
        $statement->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            static fn (array $row): array => [
                'organization_id' => (string) $row['organization_id'],
                'id' => (string) $row['id'],
                'correlation_id' => (string) $row['correlation_id'],
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC) ?: [],
        );
    }
}
