<?php
declare(strict_types=1);

namespace Kernel\Audit\Contract;

use Kernel\Audit\AuditEntry;

interface AuditRepositoryInterface
{
    public function append(AuditEntry $entry): void;
}
