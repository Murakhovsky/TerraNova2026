<?php
declare(strict_types=1);

namespace Kernel\Action\Contract;

use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Action\ExecutionResult;

interface ActionRepositoryInterface
{
    public function save(Action $action): Action;
    public function find(string $organizationId, string $id): ?Action;
    public function existsByIdempotencyKey(string $organizationId, string $key): bool;
    public function transition(string $organizationId, string $id, ActionStatus $from, ActionStatus $to): bool;
    public function claim(string $organizationId, string $id, string $workerId): ?Action;
    public function claimNext(string $workerId): ?Action;
    public function finish(Action $action, ExecutionResult $result): void;
    public function requeueStale(int $olderThanSeconds): int;
}
