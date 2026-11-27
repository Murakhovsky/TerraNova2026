<?php
declare(strict_types=1);

namespace App\Web\Operations\Read;

/** Tenant-scoped READ projections of native runtime records. */
interface OperationListReadInterface
{
    public function rows(string $org, string $source, int $limit, ?string $id = null, ?array $before = null, string $correlation = '', ?\App\Web\Experience\Model\EntityRef $entity = null, ?\App\Web\Operations\OperationStatus $status = null): array;
    public function workflowGraph(string $org, string $run): array;
    public function actionAttempts(string $org, string $action): array;
    public function workflowProof(string $org, string $run): array;
    public function importItems(string $org, string $batch, int $after = -1): array;
}
