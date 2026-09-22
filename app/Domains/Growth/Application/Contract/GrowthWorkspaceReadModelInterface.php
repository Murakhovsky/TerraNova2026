<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthWorkspaceReadModelInterface
{
    /** @return array<string,mixed> */
    public function overview(string $organizationId): array;

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function candidates(string $organizationId,array $filters=[],int $limit=100): array;

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function accounts(string $organizationId,array $filters=[],int $limit=100): array;
}
