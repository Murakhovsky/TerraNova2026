<?php
declare(strict_types=1);

namespace App\Web\Operations\Read;

/** Tenant-scoped READ projections of native runtime records. */
interface OperationListReadInterface
{
    public function rows(string $org, string $source, int $limit, ?string $id = null): array;
    public function workflowProof(string $org, string $run): array;
    public function importItems(string $org, string $batch): array;
}
