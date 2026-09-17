<?php

declare(strict_types=1);

namespace Kernel\Operations\Service;

use Kernel\Operations\Contract\OperationsReadModelInterface;

/**
 * Framework-agnostic read semantics shared by the legacy and Symfony HTTP layers.
 *
 * Keeping section/detail selection here prevents the strangler runtimes from
 * drifting while transport, authentication and routing are migrated separately.
 */
final class OperationsSectionReader
{
    public function __construct(
        private readonly OperationsReadModelInterface $operations,
    ) {
    }

    public function section(string $organizationId, string $section, int $limit = 100): array
    {
        $overview = $this->operations->overview($organizationId, $limit);

        return $overview[$section] ?? [];
    }

    public function item(string $organizationId, string $section, string $id, int $limit = 100): ?array
    {
        foreach ($this->section($organizationId, $section, $limit) as $item) {
            if (($item['id'] ?? null) === $id) {
                return $item;
            }
        }

        return null;
    }
}
