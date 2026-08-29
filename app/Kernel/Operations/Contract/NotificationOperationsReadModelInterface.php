<?php
declare(strict_types=1);

namespace Kernel\Operations\Contract;

/** Read-only operational state for a technical notification outbox. */
interface NotificationOperationsReadModelInterface
{
    public function outboxStats(): array;
}
