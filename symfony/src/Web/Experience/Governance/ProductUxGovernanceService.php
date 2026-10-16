<?php
declare(strict_types=1);

namespace App\Web\Experience\Governance;

use App\Web\Experience\Registry\PageContractRegistryInterface;
use App\Web\Experience\Registry\PageExperienceStatus;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

final readonly class ProductUxGovernanceService
{
    public function __construct(
        private PageContractRegistryInterface $pages,
        #[Autowire('%kernel.project_dir%/../resources/experience/personas.yaml')] private string $personasManifest,
        #[Autowire('%kernel.project_dir%/../resources/experience/journeys.yaml')] private string $journeysManifest,
        #[Autowire('%kernel.project_dir%/../resources/experience/terminology.yaml')] private string $terminologyManifest,
        #[Autowire('%kernel.project_dir%/../resources/experience/ux-debt.yaml')] private string $uxDebtManifest,
        #[Autowire('%kernel.project_dir%/../resources/experience/design-system/gaps.yaml')] private string $designSystemGapsManifest,
    ) {}

    public function report(): ProductUxGovernanceReport
    {
        $personasDoc = $this->document($this->personasManifest);
        $journeysDoc = $this->document($this->journeysManifest);
        $terminologyDoc = $this->document($this->terminologyManifest);
        $uxDebtDoc = $this->document($this->uxDebtManifest);
        $designGapDoc = $this->document($this->designSystemGapsManifest);

        $personaDefinitions = is_array($personasDoc['personas'] ?? null) ? $personasDoc['personas'] : [];
        $personaIds = array_values(array_map('strval', array_keys($personaDefinitions)));
        $pageIds = array_map(static fn ($page): string => $page->id->value, $this->pages->all());

        $missingPersonaReferences = [];
        $genericContractCopy = [];
        $priorityCounts = ['P0' => 0, 'P1' => 0, 'P2' => 0, 'P3' => 0];
        $activePages = 0;

        foreach ($this->pages->all() as $page) {
            foreach ($page->personas as $persona) {
                if (!in_array($persona, $personaIds, true)) {
                    $missingPersonaReferences[] = $page->id->value.':'.$persona;
                }
            }

            if (
                str_contains($page->primaryGoal, 'Complete the primary task for ')
                || (str_starts_with($page->pagePurpose, 'Canonical ') && str_contains($page->pagePurpose, ' surface for '))
            ) {
                $genericContractCopy[] = $page->id->value;
            }

            if (in_array($page->status, [
                PageExperienceStatus::Deprecated,
                PageExperienceStatus::Superseded,
                PageExperienceStatus::Exempt,
            ], true)) {
                continue;
            }

            ++$activePages;
            if (isset($priorityCounts[$page->priority])) {
                ++$priorityCounts[$page->priority];
            }
        }

        $journeys = is_array($journeysDoc['journeys'] ?? null) ? $journeysDoc['journeys'] : [];
        $journeyUnknownPersonas = [];
        $journeyUnknownPages = [];
        foreach ($journeys as $journey) {
            if (!is_array($journey)) {
                continue;
            }
            $journeyId = (string) ($journey['id'] ?? 'unknown');
            foreach ($this->strings($journey['personas'] ?? []) as $persona) {
                if (!in_array($persona, $personaIds, true)) {
                    $journeyUnknownPersonas[] = $journeyId.':'.$persona;
                }
            }
            foreach ($this->strings($journey['pages'] ?? []) as $pageId) {
                if (!in_array($pageId, $pageIds, true)) {
                    $journeyUnknownPages[] = $journeyId.':'.$pageId;
                }
            }
        }

        $terms = is_array($terminologyDoc['terms'] ?? null) ? $terminologyDoc['terms'] : [];
        $termIds = [];
        foreach ($terms as $term) {
            if (is_array($term)) {
                $termIds[] = (string) ($term['id'] ?? '');
            }
        }
        $counts = array_count_values(array_filter($termIds));
        $duplicateTerminologyIds = array_keys(array_filter($counts, static fn (int $count): bool => $count > 1));

        [$openUxDebt, $blockingUxDebt] = $this->debtCounts($uxDebtDoc['items'] ?? []);
        [$openDesignGaps, $blockingDesignGaps] = $this->debtCounts($designGapDoc['items'] ?? []);

        $p0p1 = ($priorityCounts['P0'] ?? 0) + ($priorityCounts['P1'] ?? 0);
        $priorityInflated = $activePages > 0 && ($p0p1 / $activePages) > 0.85;

        sort($missingPersonaReferences);
        sort($journeyUnknownPersonas);
        sort($journeyUnknownPages);
        sort($duplicateTerminologyIds);
        sort($genericContractCopy);

        return new ProductUxGovernanceReport(
            personas: count($personaDefinitions),
            journeys: count($journeys),
            terminologyTerms: count($terms),
            openUxDebt: $openUxDebt,
            blockingUxDebt: $blockingUxDebt,
            openDesignSystemGaps: $openDesignGaps,
            blockingDesignSystemGaps: $blockingDesignGaps,
            missingPersonaReferences: $missingPersonaReferences,
            journeyUnknownPersonas: $journeyUnknownPersonas,
            journeyUnknownPages: $journeyUnknownPages,
            duplicateTerminologyIds: $duplicateTerminologyIds,
            genericContractCopy: $genericContractCopy,
            priorityCounts: $priorityCounts,
            priorityInflated: $priorityInflated,
        );
    }

    /** @return array<string,mixed> */
    private function document(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Experience governance manifest missing: '.$path);
        }
        $document = Yaml::parseFile($path);
        if (!is_array($document)) {
            throw new RuntimeException('Experience governance manifest invalid: '.$path);
        }
        return $document;
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $value,
        )));
    }

    /** @return array{0:int,1:int} */
    private function debtCounts(mixed $items): array
    {
        if (!is_array($items)) {
            return [0, 0];
        }
        $open = 0;
        $blocking = 0;
        foreach ($items as $item) {
            if (!is_array($item) || strtoupper((string) ($item['status'] ?? 'OPEN')) !== 'OPEN') {
                continue;
            }
            ++$open;
            if (strtoupper((string) ($item['severity'] ?? '')) === 'BLOCKER') {
                ++$blocking;
            }
        }
        return [$open, $blocking];
    }
}
