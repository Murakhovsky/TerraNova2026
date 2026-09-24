<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

interface PropertyWorkspaceReadModelInterface
{
    /** @return array<string,mixed> */
    public function overview(string $organizationId, int $limit = 6): array;

    /** @return array{items:list<array<string,mixed>>,stats:array<string,int>,filters:array<string,mixed>,total:int} */
    public function inventory(string $organizationId, array $filters = [], int $limit = 100, int $offset = 0): array;

    /** @return array{items:list<array<string,mixed>>,counts:array<string,int>} */
    public function submissions(string $organizationId, string $status = '', int $limit = 100): array;

    /** @return array<string,mixed>|null */
    public function submission(string $organizationId, int $id): ?array;
}
