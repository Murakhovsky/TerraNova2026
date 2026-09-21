<?php
declare(strict_types=1);

namespace Kernel\Action\Contract;

use Kernel\Action\RuntimeActionProjection;

interface RuntimeActionReadModelInterface
{
    /**
     * @param list<string> $targetTypes
     * @return list<RuntimeActionProjection>
     */
    public function forEntity(
        string $organizationId,
        array $targetTypes,
        string $targetId,
        int $limit = 20,
    ): array;
}
