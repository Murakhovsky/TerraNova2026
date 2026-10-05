<?php
declare(strict_types=1);

namespace App\Engineering\Domain\DomainDevelopment;

final class DomainPathReservationPolicy
{
    /** @param array<string,mixed> $candidate
     *  @param list<array<string,mixed>> $active
     */
    public function conflicts(array $candidate, array $active): bool
    {
        $candidatePaths = $this->reservedPaths($candidate);
        if ($candidatePaths === []) return false;

        foreach ($active as $feature) {
            foreach ($candidatePaths as $left) {
                foreach ($this->reservedPaths($feature) as $right) {
                    if ($this->overlaps($left, $right)) return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> */
    private function reservedPaths(array $feature): array
    {
        $paths = [];
        foreach (['owned_paths', 'shared_paths'] as $key) {
            foreach (is_array($feature[$key] ?? null) ? $feature[$key] : [] as $path) {
                $normalized = trim(str_replace('\\', '/', (string) $path), '/');
                if ($normalized !== '') $paths[] = $normalized;
            }
        }
        return array_values(array_unique($paths));
    }

    private function overlaps(string $left, string $right): bool
    {
        return $left === $right
            || str_starts_with($left.'/', $right.'/')
            || str_starts_with($right.'/', $left.'/');
    }
}
