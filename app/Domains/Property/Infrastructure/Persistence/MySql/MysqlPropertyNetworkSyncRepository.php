<?php
declare(strict_types=1);

namespace Domains\Property\Infrastructure\Persistence\MySql;

use Domains\Property\Application\Contract\PropertyNetworkSyncRepositoryInterface;
use Domains\Property\Network\PropertyNetworkRecord;
use PDO;

final readonly class MysqlPropertyNetworkSyncRepository implements PropertyNetworkSyncRepositoryInterface
{
    public function __construct(private PDO $connection) {}

    public function connector(string $organizationId, string $connectorId): ?array
    {
        return $this->one('SELECT organization_id,connector_id,source_id,adapter_code,name,connector_type,direction,status,
                configuration_reference,import_cursor,export_cursor,last_import_at,last_export_at
            FROM tn_property_network_connectors
            WHERE organization_id=:organization_id AND connector_id=:connector_id LIMIT 1', [
            'organization_id' => $organizationId,
            'connector_id' => $connectorId,
        ]);
    }

    public function beginRun(string $organizationId, string $connectorId, string $direction, ?string $cursorBefore, ?string $correlationId): string
    {
        $runId = 'NWRUN-' . bin2hex(random_bytes(16));
        $this->connection->prepare('INSERT INTO tn_property_network_sync_runs
            (organization_id,sync_run_id,connector_id,direction,status,cursor_before,correlation_id,started_at)
            VALUES (:organization_id,:sync_run_id,:connector_id,:direction,"RUNNING",:cursor_before,:correlation_id,NOW())')->execute([
            'organization_id' => $organizationId,
            'sync_run_id' => $runId,
            'connector_id' => $connectorId,
            'direction' => $direction,
            'cursor_before' => $cursorBefore,
            'correlation_id' => $correlationId,
        ]);
        return $runId;
    }

    public function alreadyProcessed(string $organizationId, string $connectorId, string $direction, PropertyNetworkRecord $record): bool
    {
        return $this->one('SELECT network_record_id FROM tn_property_network_records
            WHERE organization_id=:organization_id AND connector_id=:connector_id AND direction=:direction
              AND external_entity_type=:entity_type AND external_id=:external_id AND payload_hash=:payload_hash
              AND status IN ("IMPORTED","EXPORTED","TOMBSTONE","SKIPPED") LIMIT 1', [
            'organization_id' => $organizationId,
            'connector_id' => $connectorId,
            'direction' => $direction,
            'entity_type' => $record->externalEntityType,
            'external_id' => $record->externalId,
            'payload_hash' => $record->payloadHash(),
        ]) !== null;
    }

    public function recordReceived(string $organizationId, string $runId, array $connector, string $direction, PropertyNetworkRecord $record): string
    {
        $recordId = 'NWREC-' . bin2hex(random_bytes(16));
        $payload = json_encode($record->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $this->connection->prepare('INSERT INTO tn_property_network_records
            (organization_id,network_record_id,connector_id,source_id,sync_run_id,direction,external_entity_type,external_id,
             external_version,operation,payload_json,payload_hash,status,observed_at,received_at)
            VALUES (:organization_id,:record_id,:connector_id,:source_id,:run_id,:direction,:entity_type,:external_id,
             :external_version,:operation,:payload,:payload_hash,"RECEIVED",:observed_at,NOW())
            ON DUPLICATE KEY UPDATE
                sync_run_id=VALUES(sync_run_id),
                external_version=VALUES(external_version),
                operation=VALUES(operation),
                payload_json=VALUES(payload_json),
                observed_at=VALUES(observed_at),
                status=IF(status="FAILED","RECEIVED",status),
                error_message=IF(status="FAILED",NULL,error_message)')->execute([
            'organization_id' => $organizationId,
            'record_id' => $recordId,
            'connector_id' => (string) $connector['connector_id'],
            'source_id' => (string) $connector['source_id'],
            'run_id' => $runId,
            'direction' => $direction,
            'entity_type' => $record->externalEntityType,
            'external_id' => $record->externalId,
            'external_version' => $record->externalVersion,
            'operation' => $record->operation,
            'payload' => $payload ?: '{}',
            'payload_hash' => $record->payloadHash(),
            'observed_at' => $record->observedAt,
        ]);

        $stored = $this->one('SELECT network_record_id FROM tn_property_network_records
            WHERE organization_id=:organization_id AND connector_id=:connector_id AND direction=:direction
              AND external_entity_type=:entity_type AND external_id=:external_id AND payload_hash=:payload_hash LIMIT 1', [
            'organization_id' => $organizationId,
            'connector_id' => (string) $connector['connector_id'],
            'direction' => $direction,
            'entity_type' => $record->externalEntityType,
            'external_id' => $record->externalId,
            'payload_hash' => $record->payloadHash(),
        ]);
        return (string) ($stored['network_record_id'] ?? $recordId);
    }

    public function markProcessed(string $organizationId, string $networkRecordId, string $status, ?int $submissionId = null, ?string $assetId = null, ?string $error = null): void
    {
        $this->connection->prepare('UPDATE tn_property_network_records
            SET status=:status,submission_id=:submission_id,asset_id=:asset_id,error_message=:error,processed_at=NOW()
            WHERE organization_id=:organization_id AND network_record_id=:record_id LIMIT 1')->execute([
            'status' => $status,
            'submission_id' => $submissionId,
            'asset_id' => $assetId,
            'error' => $error !== null ? mb_substr($error, 0, 1000) : null,
            'organization_id' => $organizationId,
            'record_id' => $networkRecordId,
        ]);
    }

    public function advanceCursor(string $organizationId, string $connectorId, string $direction, ?string $cursor): void
    {
        $column = strtoupper($direction) === 'IMPORT' ? 'import_cursor' : 'export_cursor';
        $timeColumn = strtoupper($direction) === 'IMPORT' ? 'last_import_at' : 'last_export_at';
        $this->connection->prepare("UPDATE tn_property_network_connectors SET {$column}=:cursor,{$timeColumn}=NOW(),updated_at=NOW()
            WHERE organization_id=:organization_id AND connector_id=:connector_id LIMIT 1")->execute([
            'cursor' => $cursor,
            'organization_id' => $organizationId,
            'connector_id' => $connectorId,
        ]);
    }

    public function completeRun(string $organizationId, string $runId, string $status, array $counts, ?string $cursorAfter): void
    {
        $this->connection->prepare('UPDATE tn_property_network_sync_runs SET status=:status,cursor_after=:cursor_after,
            records_received=:received,records_imported=:imported,records_exported=:exported,records_skipped=:skipped,
            records_failed=:failed,tombstones=:tombstones,finished_at=NOW()
            WHERE organization_id=:organization_id AND sync_run_id=:run_id LIMIT 1')->execute([
            'status' => $status,
            'cursor_after' => $cursorAfter,
            'received' => (int) ($counts['received'] ?? 0),
            'imported' => (int) ($counts['imported'] ?? 0),
            'exported' => (int) ($counts['exported'] ?? 0),
            'skipped' => (int) ($counts['skipped'] ?? 0),
            'failed' => (int) ($counts['failed'] ?? 0),
            'tombstones' => (int) ($counts['tombstones'] ?? 0),
            'organization_id' => $organizationId,
            'run_id' => $runId,
        ]);
    }

    public function failRun(string $organizationId, string $runId, string $error): void
    {
        $this->connection->prepare('UPDATE tn_property_network_sync_runs SET status="FAILED",error_summary=:error,finished_at=NOW()
            WHERE organization_id=:organization_id AND sync_run_id=:run_id LIMIT 1')->execute([
            'error' => mb_substr($error, 0, 1000),
            'organization_id' => $organizationId,
            'run_id' => $runId,
        ]);
    }

    private function one(string $sql, array $params): ?array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
