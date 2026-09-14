<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

interface PropertyWorkspaceReadModelInterface
{
    /** @return array<string, mixed> */
    public function overview(string $organizationId, int $limit = 6): array;
}
