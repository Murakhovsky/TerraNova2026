<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

use Domains\Property\Network\PropertyNetworkRecord;

interface PropertyNetworkSyncRepositoryInterface
{
    public function connector(string $organizationId, string $connectorId): ?array;

    public function beginRun(string $organizationId, string $connectorId, string $direction, ?string $cursorBefore, ?string $correlationId): string;

    public function alreadyProcessed(string $organizationId, string $connectorId, string $direction, PropertyNetworkRecord $record): bool;

    public function recordReceived(string $organizationId, string $runId, array $connector, string $direction, PropertyNetworkRecord $record): string;

    public function markProcessed(string $organizationId, string $networkRecordId, string $status, ?int $submissionId = null, ?string $assetId = null, ?string $error = null): void;

    public function advanceCursor(string $organizationId, string $connectorId, string $direction, ?string $cursor): void;

    public function completeRun(string $organizationId, string $runId, string $status, array $counts, ?string $cursorAfter): void;

    public function failRun(string $organizationId, string $runId, string $error): void;
}
