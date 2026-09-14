<?php
declare(strict_types=1);

namespace Kernel\Operations\Contract;

interface OperationsReadModelInterface
{
    /** @return array<string, mixed> */
    public function overview(string $organizationId, int $limit = 30): array;

    /** @return array{decision: ?array, actions: list<array>} */
    public function dealIntelligence(string $organizationId, int $dealId): array;

    /** @return array<string, mixed> */
    public function health(): array;
}
