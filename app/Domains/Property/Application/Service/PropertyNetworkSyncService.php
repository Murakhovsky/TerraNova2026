<?php
declare(strict_types=1);

namespace Domains\Property\Application\Service;

use Domains\Property\Application\Contract\PropertyNetworkExportPort;
use Domains\Property\Application\Contract\PropertyNetworkIntakePort;
use Domains\Property\Application\Contract\PropertyNetworkSyncRepositoryInterface;
use Domains\Property\Network\PropertyNetworkRecord;
use RuntimeException;
use Throwable;

final readonly class PropertyNetworkSyncService
{
    public function __construct(
        private PropertyNetworkConnectorRegistry $connectors,
        private PropertyNetworkSyncRepositoryInterface $repository,
        private PropertyNetworkIntakePort $intake,
        private PropertyNetworkExportPort $export,
    ) {}

    public function import(string $organizationId, string $connectorId, int $limit = 200, int $maxBatches = 50, ?string $correlationId = null): array
    {
        $connector = $this->descriptor($organizationId, $connectorId, 'IMPORT');
        $adapter = $this->connectors->get((string) $connector['adapter_code']);
        $cursor = $this->cursor($connector, 'IMPORT');
        $runId = $this->repository->beginRun($organizationId, $connectorId, 'IMPORT', $cursor, $correlationId);
        $counts = ['received' => 0, 'imported' => 0, 'exported' => 0, 'skipped' => 0, 'failed' => 0, 'tombstones' => 0];

        try {
            for ($batchNumber = 0; $batchNumber < max(1, $maxBatches); $batchNumber++) {
                $batch = $adapter->pull($connector, $cursor, max(1, min(1000, $limit)));
                $batchFailed = false;

                foreach ($batch->records as $record) {
                    $counts['received']++;
                    if ($this->repository->alreadyProcessed($organizationId, $connectorId, 'IMPORT', $record)) {
                        $counts['skipped']++;
                        continue;
                    }

                    $recordId = $this->repository->recordReceived($organizationId, $runId, $connector, 'IMPORT', $record);
                    if ($record->operation === PropertyNetworkRecord::DELETE) {
                        $this->repository->markProcessed($organizationId, $recordId, 'TOMBSTONE');
                        $counts['tombstones']++;
                        continue;
                    }

                    try {
                        $submissionId = $this->intake->import($organizationId, $connector, $record);
                        $this->repository->markProcessed($organizationId, $recordId, 'IMPORTED', $submissionId);
                        $counts['imported']++;
                    } catch (Throwable $error) {
                        $this->repository->markProcessed($organizationId, $recordId, 'FAILED', error: $error->getMessage());
                        $counts['failed']++;
                        $batchFailed = true;
                    }
                }

                if ($batchFailed) break;

                $cursor = $batch->nextCursor ?? $cursor;
                $this->repository->advanceCursor($organizationId, $connectorId, 'IMPORT', $cursor);
                if (!$batch->hasMore) break;
            }

            $status = $counts['failed'] > 0 ? 'PARTIAL' : 'SUCCEEDED';
            $this->repository->completeRun($organizationId, $runId, $status, $counts, $cursor);
            return ['run_id' => $runId, 'status' => $status, 'cursor' => $cursor, 'counts' => $counts];
        } catch (Throwable $error) {
            $this->repository->failRun($organizationId, $runId, $error->getMessage());
            throw $error;
        }
    }

    public function export(string $organizationId, string $connectorId, int $limit = 200, ?string $correlationId = null): array
    {
        $connector = $this->descriptor($organizationId, $connectorId, 'EXPORT');
        $adapter = $this->connectors->get((string) $connector['adapter_code']);
        $cursor = $this->cursor($connector, 'EXPORT');
        $runId = $this->repository->beginRun($organizationId, $connectorId, 'EXPORT', $cursor, $correlationId);
        $counts = ['received' => 0, 'imported' => 0, 'exported' => 0, 'skipped' => 0, 'failed' => 0, 'tombstones' => 0];

        try {
            $batch = $this->export->batch($organizationId, $connector, $cursor, max(1, min(1000, $limit)));
            $pending = [];
            $recordIds = [];

            foreach ($batch->records as $record) {
                if ($this->repository->alreadyProcessed($organizationId, $connectorId, 'EXPORT', $record)) {
                    $counts['skipped']++;
                    continue;
                }
                $recordIds[$record->key()] = $this->repository->recordReceived($organizationId, $runId, $connector, 'EXPORT', $record);
                $pending[] = $record;
            }

            if ($pending !== []) {
                $delivery = $adapter->push($connector, $pending, $cursor);
                foreach ($pending as $record) {
                    $key = $record->key();
                    if ($delivery->succeeded($key)) {
                        $this->repository->markProcessed($organizationId, $recordIds[$key], 'EXPORTED');
                        $counts['exported']++;
                    } else {
                        $this->repository->markProcessed($organizationId, $recordIds[$key], 'FAILED', error: $delivery->error($key) ?? 'Connector did not acknowledge record.');
                        $counts['failed']++;
                    }
                }
            }

            if ($counts['failed'] === 0 && $batch->nextCursor !== null) $cursor = $batch->nextCursor;
            if ($counts['failed'] === 0) $this->repository->advanceCursor($organizationId, $connectorId, 'EXPORT', $cursor);
            $status = $counts['failed'] > 0 ? 'PARTIAL' : 'SUCCEEDED';
            $this->repository->completeRun($organizationId, $runId, $status, $counts, $cursor);
            return ['run_id' => $runId, 'status' => $status, 'cursor' => $cursor, 'counts' => $counts];
        } catch (Throwable $error) {
            $this->repository->failRun($organizationId, $runId, $error->getMessage());
            throw $error;
        }
    }

    private function descriptor(string $organizationId, string $connectorId, string $direction): array
    {
        $connector = $this->repository->connector($organizationId, $connectorId);
        if ($connector === null) throw new RuntimeException('Property network connector not found.');
        if (strtoupper((string) ($connector['status'] ?? '')) !== 'ACTIVE') throw new RuntimeException('Property network connector is not active.');

        $configured = strtoupper((string) ($connector['direction'] ?? ''));
        if (!in_array($configured, [$direction, 'BIDIRECTIONAL'], true)) {
            throw new RuntimeException('Property network connector does not allow ' . strtolower($direction) . '.');
        }
        if (trim((string) ($connector['adapter_code'] ?? '')) === '') throw new RuntimeException('Property network connector adapter code is missing.');
        return $connector;
    }

    private function cursor(array $connector, string $direction): ?string
    {
        $key = $direction === 'IMPORT' ? 'import_cursor' : 'export_cursor';
        $cursor = trim((string) ($connector[$key] ?? ''));
        return $cursor === '' ? null : $cursor;
    }
}
