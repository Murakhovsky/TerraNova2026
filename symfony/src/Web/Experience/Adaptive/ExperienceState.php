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
}
