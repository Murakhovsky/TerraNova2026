<?php
declare(strict_types=1);

namespace App\Web\Experience\Golden;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

final readonly class GoldenDecisionRegistry
{
    private const ALLOWED = ['PENDING', 'ACCEPT', 'REQUEST_CHANGES'];

    public function __construct(
        #[Autowire('%kernel.project_dir%/../resources/experience/golden-decisions.yaml')]
        private string $manifest,
        #[Autowire('%kernel.project_dir%/../resources/experience/golden-set.yaml')]
        private string $goldenManifest,
    ) {}

    /** @return array<string,array<string,mixed>> */
    public function all(): array
    {
        $golden = Yaml::parseFile($this->goldenManifest);
        $document = Yaml::parseFile($this->manifest);

        $ids = array_values(array_map('strval', is_array($golden['pages'] ?? null) ? $golden['pages'] : []));
        $decisions = is_array($document['decisions'] ?? null) ? $document['decisions'] : [];

        if (count($ids) !== 8 || count($decisions) !== 8) {
            throw new RuntimeException('Golden decision ledger must contain exactly eight decisions.');
        }

        if (array_values(array_keys($decisions)) !== $ids) {
            throw new RuntimeException('Golden decision ledger must match Golden Set order and ids exactly.');
        }

        $normalized = [];
        foreach ($decisions as $id => $decision) {
            if (!is_array($decision)) {
                throw new RuntimeException('Invalid Golden decision entry: '.$id);
            }

            $state = strtoupper(trim((string) ($decision['decision'] ?? '')));
            if (!in_array($state, self::ALLOWED, true)) {
                throw new RuntimeException('Unsupported Golden decision '.$state.' for '.$id);
            }

            $actor = $this->nullableString($decision['actor'] ?? null);
            $decidedAt = $this->nullableString($decision['decided_at'] ?? null);
            $evidence = is_array($decision['evidence'] ?? null)
                ? array_values(array_filter(array_map('strval', $decision['evidence'])))
                : [];
            $note = $this->nullableString($decision['note'] ?? null);

            if ($state === 'ACCEPT' && ($actor === null || $decidedAt === null || $evidence === [])) {
                throw new RuntimeException('Golden ACCEPT requires actor, decided_at and evidence for '.$id);
            }

            if ($state === 'REQUEST_CHANGES' && ($actor === null || $decidedAt === null || $note === null)) {
                throw new RuntimeException('Golden REQUEST_CHANGES requires actor, decided_at and note for '.$id);
            }

            $normalized[(string) $id] = [
                'decision' => $state,
                'actor' => $actor,
                'decided_at' => $decidedAt,
                'evidence' => $evidence,
                'note' => $note,
            ];
        }

        return $normalized;
    }

    /** @return array<string,mixed> */
    public function get(string $id): array
    {
        $all = $this->all();
        if (!isset($all[$id])) {
            throw new RuntimeException('Unknown Golden decision id: '.$id);
        }
        return $all[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    public function accepted(string $id): bool
    {
        return ($this->get($id)['decision'] ?? null) === 'ACCEPT';
    }

    public function acceptedCount(): int
    {
        return count(array_filter($this->all(), static fn (array $d): bool => $d['decision'] === 'ACCEPT'));
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) return null;
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }
}
