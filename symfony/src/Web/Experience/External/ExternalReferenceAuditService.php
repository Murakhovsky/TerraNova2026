<?php
declare(strict_types=1);

namespace App\Web\Experience\External;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

final readonly class ExternalReferenceAuditService
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/../resources/experience/external-reference.yaml')]
        private string $manifest,
        #[Autowire('%kernel.project_dir%/..')]
        private string $repositoryRoot,
    ) {}

    public function audit(): ExternalReferenceAuditReport
    {
        if (!is_file($this->manifest)) {
            throw new RuntimeException('External Experience reference manifest is missing.');
        }

        $document = Yaml::parseFile($this->manifest);
        $definitions = is_array($document) && is_array($document['pages'] ?? null)
            ? $document['pages']
            : [];

        if (count($definitions) !== 4) {
            throw new RuntimeException('External Experience reference set must contain exactly four surfaces.');
        }

        $pages = [];
        foreach ($definitions as $id => $definition) {
            if (!is_array($definition)) {
                throw new RuntimeException('Invalid External Experience reference: '.$id);
            }

            $relative = (string) ($definition['template'] ?? '');
            $path = $this->repositoryRoot.'/'.ltrim($relative, '/');
            $source = is_file($path) ? (string) file_get_contents($path) : '';
            $required = $this->strings($definition['required'] ?? []);
            $forbidden = $this->strings($definition['forbidden'] ?? []);
            $missing = array_values(array_filter($required, static fn (string $marker): bool => !str_contains($source, $marker)));
            $foundForbidden = array_values(array_filter($forbidden, static fn (string $marker): bool => str_contains($source, $marker)));

            $pages[] = [
                'id' => (string) $id,
                'path' => (string) ($definition['path'] ?? ''),
                'surface' => (string) ($definition['surface'] ?? ''),
                'archetype' => (string) ($definition['archetype'] ?? ''),
                'template' => $relative,
                'passed' => $source !== '' && $missing === [] && $foundForbidden === [],
                'missing' => $missing,
                'forbidden' => $foundForbidden,
            ];
        }

        return new ExternalReferenceAuditReport($pages);
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_map('strval', $value)) : [];
    }
}
