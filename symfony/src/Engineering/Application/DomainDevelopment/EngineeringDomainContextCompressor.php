<?php
declare(strict_types=1);

namespace App\Engineering\Application\DomainDevelopment;

final readonly class EngineeringDomainContextCompressor
{
    public function __construct(private int $fullArtifactByteLimit = 24000, private int $maxSectionItems = 60) {}

    /** @param array<string,mixed> $artifact @param list<string> $relevantKeys @return array<string,mixed> */
    public function artifact(array $artifact, array $relevantKeys = []): array
    {
        $content = is_array($artifact['content'] ?? null) ? $artifact['content'] : [];
        $encoded = json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = trim((string) ($artifact['content_hash'] ?? ''));
        if ($hash === '') $hash = hash('sha256', $encoded);

        $sections = [];
        foreach ($relevantKeys as $key) {
            if (!array_key_exists($key, $content)) continue;
            $sections[$key] = $this->bounded($content[$key]);
        }
        if ($sections === []) {
            foreach (array_slice(array_keys($content), 0, 8) as $key) {
                $sections[(string) $key] = $this->bounded($content[$key]);
            }
        }

        return [
            'artifact_ref' => [
                'id' => $artifact['id'] ?? null,
                'type' => $artifact['type'] ?? null,
                'version' => $artifact['version'] ?? null,
            ],
            'full_artifact' => strlen($encoded) <= $this->fullArtifactByteLimit ? $content : null,
            'canonical_summary' => $this->summary($content),
            'hash' => $hash,
            'relevant_sections' => $sections,
        ];
    }

    /** @param array<string,mixed> $index @return array<string,mixed> */
    public function repositoryIndex(array $index): array
    {
        $sections = [];
        foreach (['classes','interfaces','services','entities','routes','migrations','tests','modules','dependencies'] as $key) {
            $values = is_array($index[$key] ?? null) ? $index[$key] : [];
            $sections[$key] = array_slice($values, 0, $this->maxSectionItems);
        }

        return [
            'full_artifact' => null,
            'canonical_summary' => [
                'repository' => $index['repository'] ?? null,
                'revision' => $index['revision'] ?? null,
                'counts' => $index['counts'] ?? [],
            ],
            'hash' => $index['hash'] ?? hash('sha256', json_encode($index, JSON_THROW_ON_ERROR)),
            'relevant_sections' => $sections,
        ];
    }

    /** @param array<string,mixed> $content @return array<string,mixed> */
    private function summary(array $content): array
    {
        $summary = ['keys' => array_slice(array_keys($content), 0, 30)];
        foreach (['key','name','purpose','status','version','scope','business_goal','primary_goal'] as $key) {
            if (array_key_exists($key, $content) && is_scalar($content[$key])) $summary[$key] = $content[$key];
        }
        foreach ($content as $key => $value) {
            if (is_array($value)) $summary['counts'][(string) $key] = count($value);
        }
        return $summary;
    }

    private function bounded(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_slice($value, 0, $this->maxSectionItems);
        return array_slice($value, 0, $this->maxSectionItems, true);
    }
}
