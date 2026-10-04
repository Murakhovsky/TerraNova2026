<?php
declare(strict_types=1);

namespace App\Web\Experience\Delivery;

use App\Web\Experience\Archetype\PageArchetypeRegistry;
use App\Web\Experience\Pattern\PatternRegistry;
use App\Web\Experience\Registry\PageContractRegistryInterface;

final readonly class PageDeliveryContextPackageBuilder
{
    public function __construct(
        private PageContractRegistryInterface $pages,
        private PageArchetypeRegistry $archetypes,
        private PatternRegistry $patterns,
    ) {}

    public function build(string $pageId): PageDeliveryContextPackage
    {
        $page = $this->pages->get($pageId);
        $archetype = $this->archetypes->get($page->archetype);
        $patternNames = array_values(array_unique(array_merge($page->requiredPatterns, $page->optionalPatterns)));

        $patterns = [];
        foreach ($patternNames as $name) {
            $patterns[$name] = $this->patterns->get($name)->toArray();
        }

        return new PageDeliveryContextPackage(
            $page->id->value,
            [
                'id' => $page->id->value,
                'route' => ['name' => $page->routeName, 'path' => $page->path, 'methods' => $page->methods],
                'surface' => $page->surface,
                'domain' => $page->domain,
                'capability' => $page->capability,
                'owner' => $page->owner,
                'priority' => $page->priority,
                'status' => $page->status->value,
                'personas' => $page->personas,
                'primary_goal' => $page->primaryGoal,
                'page_purpose' => $page->pagePurpose,
                'archetype' => $page->archetype,
                'required_patterns' => $page->requiredPatterns,
                'optional_patterns' => $page->optionalPatterns,
                'primary_action' => $page->primaryAction,
                'states' => $page->states,
                'responsive' => $page->responsive,
                'qa' => $page->qa,
                'quality' => $page->quality->toArray(),
            ],
            $archetype->toArray(),
            $patterns,
            [
                'functional' => true,
                'browser' => true,
                'responsive' => ['desktop', 'mobile'],
                'accessibility' => ['axe A/AA', 'keyboard critical path'],
                'visual_evidence' => 'screenshots required',
                'human_acceptance' => 'required before V1_READY',
                'golden_target' => 4,
                'production_acceptable' => 3,
            ],
            [
                'Reuse the existing Symfony Experience Platform and canonical components.',
                'Do not introduce a second frontend runtime or Domain-owned design system.',
                'Do not move business rules into Twig, Stimulus or browser state.',
                'Preserve tenant, permission, CSRF and idempotency boundaries.',
                'Implement every declared state that is applicable to the page.',
                'Do not mark human_acceptance=true automatically.',
                'Do not promote V1_READY without human acceptance and quality thresholds.',
                'Return concrete QA evidence for every claimed PASS.',
            ],
        );
    }
}
