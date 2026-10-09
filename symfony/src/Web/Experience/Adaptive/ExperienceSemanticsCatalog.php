<?php
declare(strict_types=1);

namespace App\Web\Experience\Adaptive;

use InvalidArgumentException;

/**
 * Descriptor inventory only. Authorized UIActionResolver, tenant context and
 * policy gates MUST run separately before any UI composition uses these hints.
 */
final readonly class ExperienceSemanticsCatalog
{
    /** @var array<string,ExperienceSemantic> */
    private array $items;

    /** @param list<ExperienceSemantic> $descriptors */
    public function __construct(array $descriptors)
    {
        $map = [];
        foreach ($descriptors as $item) {
            if (!$item instanceof ExperienceSemantic || isset($map[$item->id])) {
                throw new InvalidArgumentException('Duplicate or invalid COS Experience descriptor.');
            }
            $map[$item->id] = $item;
        }
        $this->items = $map;
    }

    public function get(string $id): ?ExperienceSemantic
    {
        return $this->items[$id] ?? null;
    }

    /** @return list<ExperienceSemantic> */
    public function all(): array
    {
        $all = array_values($this->items);
        usort($all, static fn (ExperienceSemantic $a, ExperienceSemantic $b): int =>
            $a->priority <=> $b->priority ?: strcmp($a->id, $b->id));
        return $all;
    }
}
