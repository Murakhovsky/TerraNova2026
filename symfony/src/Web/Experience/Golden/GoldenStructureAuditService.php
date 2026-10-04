<?php

declare(strict_types=1);

namespace App\Web\Experience\Golden;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

final readonly class GoldenStructureAuditService
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/../resources/experience/golden-structure.yaml')]
        private string $manifest,
        #[Autowire('%kernel.project_dir%/..')]
        private string $repositoryRoot,
    ) {
    }

    public function audit(): GoldenStructureAuditReport
    {
        if (!is_file($this->manifest)) {
            throw new RuntimeException('Golden structure manifest is missing.');
        }

        $document = Yaml::parseFile($this->manifest);
        $definitions = is_array($document) && is_array($document['pages'] ?? null)
            ? $document['pages']
            : [];

        $pages = [];

        foreach ($definitions as $id => $definition) {
            if (!is_array($definition)) {
                throw new RuntimeException('Invalid Golden structure definition: ' . $id);
            }

            $relative = (string) ($definition['template'] ?? '');
            $path = $this->repositoryRoot . '/' . ltrim($relative, '/');

            if ($relative === '' || !is_file($path)) {
                $pages[] = [
                    'id' => (string) $id,
                    'template' => $relative,
                    'passed' => false,
                    'missing' => ['template'],
                    'forbidden' => [],
                ];
                continue;
            }

            $source = (string) file_get_contents($path);
            $scope = $this->scope(
                $source,
                isset($definition['scope_start']) ? (string) $definition['scope_start'] : null,
                isset($definition['scope_end']) ? (string) $definition['scope_end'] : null,
            );

            $required = $this->strings($definition['required'] ?? []);
            $forbidden = $this->strings($definition['forbidden'] ?? []);

            $missing = array_values(array_filter(
                $required,
                static fn (string $marker): bool => !str_contains($scope, $marker),
            ));
            $foundForbidden = array_values(array_filter(
                $forbidden,
                static fn (string $marker): bool => str_contains($scope, $marker),
            ));

            $pages[] = [
                'id' => (string) $id,
                'template' => $relative,
                'passed' => $missing === [] && $foundForbidden === [],
                'missing' => $missing,
                'forbidden' => $foundForbidden,
            ];
        }

        return new GoldenStructureAuditReport($pages);
    }

    private function scope(string $source, ?string $start, ?string $end): string
    {
        if ($start === null || $start === '') {
            return $source;
        }

        $offset = strpos($source, $start);
        if ($offset === false) {
            return '';
        }

        $slice = substr($source, $offset);
        if ($end === null || $end === '') {
            return $slice;
        }

        $endOffset = strpos($slice, $end);
        return $endOffset === false ? $slice : substr($slice, 0, $endOffset);
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_map('strval', $value)) : [];
    }
}
