<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Domains\Property\Application\Contract\PropertyNetworkConnectorInterface;
use Domains\Property\Application\Contract\PropertyNetworkExportPort;
use Domains\Property\Application\Contract\PropertyNetworkIntakePort;
use Domains\Property\Application\Contract\PropertyNetworkSyncRepositoryInterface;
use Domains\Property\Application\Service\PropertyNetworkConnectorRegistry;
use Domains\Property\Application\Service\PropertyNetworkSyncService;
use Domains\Property\Network\PropertyNetworkBatch;
use Domains\Property\Network\PropertyNetworkDeliveryResult;
use Domains\Property\Network\PropertyNetworkRecord;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$hashA = (new PropertyNetworkRecord('property', '52', ['b' => 2, 'a' => ['y' => 2, 'x' => 1]]))->payloadHash();
$hashB = (new PropertyNetworkRecord('property', '52', ['a' => ['x' => 1, 'y' => 2], 'b' => 2]))->payloadHash();
$assert($hashA === $hashB, 'Network payload hash must be deterministic regardless of map key order.');

$connector = new class implements PropertyNetworkConnectorInterface {
    /** @var list<PropertyNetworkRecord> */
    public array $pullRecords = [];
    public int $pullCalls = 0;
    public int $pushCalls = 0;

    public function code(): string { return 'fixture'; }

    public function pull(array $connector, ?string $cursor, int $limit = 200): PropertyNetworkBatch
    {
        $this->pullCalls++;
        return new PropertyNetworkBatch($this->pullRecords, 'import-' . $this->pullCalls, false);
    }

    public function push(array $connector, array $records, ?string $cursor = null): PropertyNetworkDeliveryResult
    {
        $this->pushCalls++;
        return new PropertyNetworkDeliveryResult(
            array_map(static fn (PropertyNetworkRecord $record): string => $record->key(), $records),
            [],
            'export-' . $this->pushCalls,
        );
    }
};

$repository = new class implements PropertyNetworkSyncRepositoryInterface {
    public array $descriptor = [
        'organization_id' => 'org-a',
        'connector_id' => 'partner-a',
        'source_id' => 'SRC-PARTNER-A',
        'adapter_code' => 'fixture',
        'name' => 'Partner A',
        'connector_type' => 'PARTNER_FEED',
        'direction' => 'BIDIRECTIONAL',
        'status' => 'ACTIVE',
        'configuration_reference' => 'config://partner-a',
        'import_cursor' => null,
        'export_cursor' => null,
    ];
    public array $records = [];
    public array $runs = [];
    public int $run = 0;

    public function connector(string $organizationId, string $connectorId): ?array { return $this->descriptor; }

    public function beginRun(string $organizationId, string $connectorId, string $direction, ?string $cursorBefore, ?string $correlationId): string
    {
        $id = 'run-' . (++$this->run);
        $this->runs[$id] = ['direction' => $direction, 'status' => 'RUNNING', 'before' => $cursorBefore];
        return $id;
    }

    public function alreadyProcessed(string $organizationId, string $connectorId, string $direction, PropertyNetworkRecord $record): bool
    {
        $key = $direction . '|' . $record->key() . '|' . $record->payloadHash();
        return isset($this->records[$key]) && in_array($this->records[$key]['status'], ['IMPORTED','EXPORTED','TOMBSTONE','SKIPPED'], true);
    }

    public function recordReceived(string $organizationId, string $runId, array $connector, string $direction, PropertyNetworkRecord $record): string
    {
        $key = $direction . '|' . $record->key() . '|' . $record->payloadHash();
        $this->records[$key] ??= ['id' => 'record-' . (count($this->records) + 1), 'status' => 'RECEIVED'];
        $this->records[$key]['status'] = $this->records[$key]['status'] === 'FAILED' ? 'RECEIVED' : $this->records[$key]['status'];
        return $this->records[$key]['id'];
    }

    public function markProcessed(string $organizationId, string $networkRecordId, string $status, ?int $submissionId = null, ?string $assetId = null, ?string $error = null): void
    {
        foreach ($this->records as &$row) {
            if ($row['id'] === $networkRecordId) {
                $row['status'] = $status;
                $row['submission_id'] = $submissionId;
                $row['error'] = $error;
                return;
            }
        }
    }

    public function advanceCursor(string $organizationId, string $connectorId, string $direction, ?string $cursor): void
    {
        $this->descriptor[$direction === 'IMPORT' ? 'import_cursor' : 'export_cursor'] = $cursor;
    }

    public function completeRun(string $organizationId, string $runId, string $status, array $counts, ?string $cursorAfter): void
    {
        $this->runs[$runId] += ['counts' => $counts, 'after' => $cursorAfter];
        $this->runs[$runId]['status'] = $status;
    }

    public function failRun(string $organizationId, string $runId, string $error): void
    {
        $this->runs[$runId]['status'] = 'FAILED';
        $this->runs[$runId]['error'] = $error;
    }
};

$intake = new class implements PropertyNetworkIntakePort {
    public array $imported = [];
    public function import(string $organizationId, array $connector, PropertyNetworkRecord $record): int
    {
        $this->imported[] = [$organizationId, $connector['connector_id'], $record->key(), $record->payloadHash()];
        return 1000 + count($this->imported);
    }
};

$export = new class implements PropertyNetworkExportPort {
    public function batch(string $organizationId, array $connector, ?string $cursor, int $limit = 200): PropertyNetworkBatch
    {
        return new PropertyNetworkBatch([
            new PropertyNetworkRecord('property_offer', 'LST-52', ['asset_id' => 'APT-52', 'price_amount' => 105000]),
        ], '42', false);
    }
};

$connector->pullRecords = [
    new PropertyNetworkRecord('property', 'APT-52', ['property_type' => 'apartment', 'city' => 'Briukhovychi', 'price_amount' => 105000]),
    new PropertyNetworkRecord('property', 'APT-OLD', [], PropertyNetworkRecord::DELETE),
];

$service = new PropertyNetworkSyncService(
    new PropertyNetworkConnectorRegistry([$connector]),
    $repository,
    $intake,
    $export,
);

$first = $service->import('org-a', 'partner-a');
$assert($first['status'] === 'SUCCEEDED', 'Initial import must succeed.');
$assert($first['counts']['imported'] === 1 && $first['counts']['tombstones'] === 1, 'UPSERT and DELETE/tombstone handling drifted.');
$assert(count($intake->imported) === 1, 'Remote DELETE must never enter canonical intake.');

$second = $service->import('org-a', 'partner-a');
$assert($second['counts']['skipped'] === 2 && count($intake->imported) === 1, 'Exact network redelivery must be idempotent.');

$connector->pullRecords = [
    new PropertyNetworkRecord('property', 'APT-52', ['property_type' => 'apartment', 'city' => 'Briukhovychi', 'price_amount' => 109000]),
];
$changed = $service->import('org-a', 'partner-a');
$assert($changed['counts']['imported'] === 1 && count($intake->imported) === 2, 'Changed external payload must create a new intake observation.');

$outbound = $service->export('org-a', 'partner-a');
$assert($outbound['status'] === 'SUCCEEDED' && $outbound['counts']['exported'] === 1, 'Canonical export delivery failed.');
$assert($repository->descriptor['export_cursor'] === '42', 'Export cursor must advance only after acknowledged delivery.');

echo "Property V0.10 external network model: OK\n";
