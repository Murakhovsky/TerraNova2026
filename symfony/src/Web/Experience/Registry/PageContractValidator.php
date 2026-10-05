<?php

declare(strict_types=1);

namespace App\Web\Experience\Registry;

use App\Web\Experience\Archetype\PageArchetypeRegistry;
use App\Web\Experience\Pattern\PatternRegistry;
use InvalidArgumentException;

final readonly class PageContractValidator
{
    public function __construct(
        private PageArchetypeRegistry $archetypes,
        private PatternRegistry $patterns,
    ) {
    }

    public function validate(PageContract $contract): void
    {
        $required = [
            'routeName' => $contract->routeName,
            'path' => $contract->path,
            'surface' => $contract->surface,
            'domain' => $contract->domain,
            'capability' => $contract->capability,
            'owner' => $contract->owner,
            'primaryGoal' => $contract->primaryGoal,
            'pagePurpose' => $contract->pagePurpose,
            'archetype' => $contract->archetype,
        ];

        foreach ($required as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(sprintf('Page %s has empty required field %s.', $contract->id->value, $field));
            }
        }

        if (!in_array($contract->priority, ['P0', 'P1', 'P2', 'P3'], true)) {
            throw new InvalidArgumentException(sprintf('Page %s has invalid priority %s.', $contract->id->value, $contract->priority));
        }

        if ($contract->methods === [] || array_diff($contract->methods, ['GET', 'HEAD']) !== []) {
            throw new InvalidArgumentException(sprintf('Page %s must describe a GET/HEAD HTML surface.', $contract->id->value));
        }

        $archetype = $this->archetypes->get($contract->archetype);
        $allowedPatterns = array_unique(array_merge(
            $archetype->requiredPatterns,
            $archetype->optionalPatterns,
            ...$archetype->requiredPatternGroups,
        ));

        foreach (array_unique(array_merge($contract->requiredPatterns, $contract->optionalPatterns)) as $pattern) {
            $this->patterns->get($pattern);
            if (!in_array($pattern, $allowedPatterns, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Page %s uses pattern %s outside archetype %s contract.',
                    $contract->id->value,
                    $pattern,
                    $contract->archetype,
                ));
            }
        }

        foreach ($archetype->requiredPatterns as $requiredPattern) {
            if (!in_array($requiredPattern, $contract->requiredPatterns, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Page %s is missing required archetype pattern %s.',
                    $contract->id->value,
                    $requiredPattern,
                ));
            }
        }

        foreach ($contract->states as $state) {
            if (!in_array($state, $archetype->states, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Page %s declares state %s unsupported by archetype %s.',
                    $contract->id->value,
                    $state,
                    $contract->archetype,
                ));
            }
        }
    }
}
