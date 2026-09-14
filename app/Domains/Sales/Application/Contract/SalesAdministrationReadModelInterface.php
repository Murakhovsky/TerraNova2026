<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

interface SalesAdministrationReadModelInterface
{
    /** @return array<string,mixed> */
    public function dashboard(string $organizationId, int $limit = 50): array;
}
