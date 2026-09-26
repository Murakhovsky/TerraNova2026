<?php

declare(strict_types=1);

namespace App\Web\Experience\Archetype;

use App\Web\Experience\Pattern\PatternRegistry;
use InvalidArgumentException;

final readonly class PagePresentationFactory
{
    public function __construct(
        private PageArchetypeRegistry $archetypes,
        private PatternRegistry $patterns,
    ) {
    }

    /**
     * @param list<string> $patterns
     */
    public function create(
        PageArchetype|string $archetype,
        array $patterns,
        string $state = 'normal',
        string $density = 'comfortable',
    ): PagePresentation {
        $definition = $this->archetypes->get($archetype);
        $patterns = array_values(array_unique($patterns));

        foreach ($patterns as $pattern) {
            $this->patterns->get($pattern);
        }

        $missing = array_values(array_diff($definition->requiredPatterns, $patterns));
        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf(
                'Page archetype %s is missing required patterns: %s',
                $definition->id->value,
                implode(', ', $missing),
            ));
        }

        foreach ($definition->requiredPatternGroups as $group) {
            if (array_intersect($group, $patterns) === []) {
                throw new InvalidArgumentException(sprintf(
                    'Page archetype %s requires one pattern from group: %s',
                    $definition->id->value,
                    implode(' | ', $group),
                ));
            }
        }

        $declared = array_merge($definition->requiredPatterns, $definition->optionalPatterns);
        foreach ($definition->requiredPatternGroups as $group) {
            array_push($declared, ...$group);
        }
        $undeclared = array_values(array_diff($patterns, array_unique($declared)));
        if ($undeclared !== []) {
            throw new InvalidArgumentException(sprintf(
                'Page archetype %s received undeclared patterns: %s',
                $definition->id->value,
                implode(', ', $undeclared),
            ));
        }

        if (!in_array($state, $definition->states, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported page state %s for archetype %s.',
                $state,
                $definition->id->value,
            ));
        }

        if (!in_array($density, $definition->densities, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported density %s for archetype %s.',
                $density,
                $definition->id->value,
            ));
        }

        return new PagePresentation($definition, $patterns, $state, $density);
    }
}
