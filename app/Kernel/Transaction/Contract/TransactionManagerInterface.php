<?php
declare(strict_types=1);

namespace Kernel\Transaction\Contract;

interface TransactionManagerInterface
{
    public function transactional(callable $operation): mixed;

    public function isActive(): bool;

    public function afterCommit(callable $callback): void;
}
