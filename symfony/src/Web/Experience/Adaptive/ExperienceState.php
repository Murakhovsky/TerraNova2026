<?php
declare(strict_types=1);

namespace App\Web\Experience\Adaptive;

use InvalidArgumentException;

/** A view selection for one workspace/entity, not independent Goal state. */
final readonly class ExperienceState
{
    public const SCHEMA_VERSION = '1.0.0';

    /** @param list<string> $expandedSections */
    public function __construct(
        public string $workspaceKey,
        public string $entityKey,
        public ExperienceMode $selectedMode,
        public array $expandedSections = [],
        public int $version = 1,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,189}$/D', $workspaceKey)
            || strlen($entityKey) > 190 || str_contains($entityKey, "\0")
            || $version < 1 || !array_is_list($expandedSections)
            || count($expandedSections) > 64
            || count($expandedSections) !== count(array_unique($expandedSections))) {
            throw new InvalidArgumentException('Invalid ExperienceState version/scope.');
        }
        foreach ($expandedSections as $id) {
            if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_.-]{0,189}$/D', $id)) {
                throw new InvalidArgumentException('Invalid ExperienceState expanded component.');
            }
        }
    }

    /** Normalize persisted legacy JSON without granting visibility to invalid section IDs. */
    public static function fromStored(string $workspaceKey, string $entityKey, array $row): self
    {
        $state = json_decode((string) ($row['state_json'] ?? ''), true);
        $expanded = is_array($state['expanded_sections'] ?? null) ? $state['expanded_sections'] : [];
        $expanded = array_slice(array_values(array_unique(array_filter($expanded,
            static fn ($id): bool => is_string($id) && preg_match('/^[a-z][a-z0-9_.-]{0,189}$/D', $id) === 1))), 0, 64);
        return new self($workspaceKey, $entityKey,
            ExperienceMode::tryFrom((string) ($row['selected_mode'] ?? '')) ?? ExperienceMode::Result,
            $expanded, max(1, (int) ($row['state_version'] ?? 1)));
    }

    /** Explicit set is idempotent and preserves unrelated section choices. */
    public function withSection(string $section, bool $expanded): self
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,189}$/D', $section)) {
            throw new InvalidArgumentException('Invalid ExperienceState expanded component.');
        }
        if (in_array($section, $this->expandedSections, true) === $expanded) {
            return $this;
        }
        $sections = $expanded ? [...$this->expandedSections, $section]
            : array_values(array_filter($this->expandedSections, static fn (string $id): bool => $id !== $section));
        return new self($this->workspaceKey, $this->entityKey, $this->selectedMode, $sections, $this->version + 1);
    }
}
