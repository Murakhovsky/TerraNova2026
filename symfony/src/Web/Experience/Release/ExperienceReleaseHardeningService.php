<?php
declare(strict_types=1);

namespace App\Web\Experience\Release;

use App\Web\Experience\DesignSystem\DesignSystemAuditService;
use App\Web\Experience\External\ExternalReferenceAuditService;
use App\Web\Experience\Golden\GoldenExperienceSet;
use App\Web\Experience\Golden\GoldenDecisionRegistry;
use App\Web\Experience\Registry\ExperienceRouteInventory;
use App\Web\Experience\Registry\PageContractRegistryInterface;
use App\Web\Experience\Registry\PageExperienceStatus;
use App\Web\Experience\Registry\RouteExemptionRegistry;

final readonly class ExperienceReleaseHardeningService
{
    public function __construct(
        private PageContractRegistryInterface $pages,
        private ExperienceRouteInventory $routes,
        private RouteExemptionRegistry $exemptions,
        private DesignSystemAuditService $designSystem,
        private GoldenExperienceSet $golden,
        private GoldenDecisionRegistry $goldenDecisions,
        private ExternalReferenceAuditService $externalReferences,
        private ExperienceAssetDebtScanner $assetDebt,
        private ExperienceRouteDebtScanner $routeDebt,
    ) {}

    public function report(): ExperienceReleaseReport
    {
        $contracts = $this->pages->all();
        $routes = $this->routes->productionHtmlRoutes();
        $exemptions = $this->exemptions->all();

        $p0 = [];
        $p1 = [];
        foreach ($contracts as $page) {
            if ($page->priority === 'P0') $p0[] = $page;
            if ($page->priority === 'P1') $p1[] = $page;
        }

        $p0Ready = count(array_filter($p0, static fn ($page): bool =>
            $page->status === PageExperienceStatus::V1Ready && $page->quality->isV1Ready()
        ));

        $p1Acceptable = count(array_filter($p1, static function ($page): bool {
            $scores = $page->quality->toArray();
            return min($scores) >= 3;
        }));

        $qa = static function (array $pages, string $key): int {
            return count(array_filter($pages, static fn ($page): bool => ($page->qa[$key] ?? false) === true));
        };

        $coverage = count($routes) > 0
            ? (int) round(((count($contracts) + count($exemptions)) / count($routes)) * 100)
            : 100;

        $design = $this->designSystem->audit();
        $golden = $this->golden->report();
        $external = $this->externalReferences->audit();
        $assets = $this->assetDebt->scan();
        $routeDebt = $this->routeDebt->scan();

        $gates = [
            'registry_coverage' => ['actual' => $coverage, 'required' => 100, 'pass' => $coverage === 100],
            'design_system' => ['pass' => $design->isGreen(), 'deprecated_components' => $design->deprecated],
            'deprecated_component_cleanup' => ['pass' => $design->deprecated === 0, 'actual' => $design->deprecated, 'required' => 0],
            'stale_route_cleanup' => [
                'pass' => $routeDebt->isGreen(),
                'missing' => count($routeDebt->missing),
                'stale' => count($routeDebt->stale),
                'mismatched' => count($routeDebt->mismatched),
            ],
            'golden_decision_ledger' => ['actual' => count($this->goldenDecisions->all()), 'required' => 8, 'pass' => count($this->goldenDecisions->all()) === 8],
            'golden_human_approval' => ['actual' => $this->goldenDecisions->acceptedCount(), 'required' => $golden->required, 'pass' => $golden->isComplete()],
            'external_reference_structure' => ['actual' => $external->passed(), 'required' => 4, 'pass' => $external->isGreen()],
            'p0_v1_ready' => ['actual' => $p0Ready, 'required' => count($p0), 'pass' => $p0Ready === count($p0)],
            'p1_production_acceptable' => ['actual' => $p1Acceptable, 'required' => count($p1), 'pass' => $p1Acceptable === count($p1)],
            'p0_p1_functional_qa' => ['actual' => $qa([...$p0, ...$p1], 'functional'), 'required' => count($p0) + count($p1)],
            'p0_p1_visual_qa' => ['actual' => $qa([...$p0, ...$p1], 'visual'), 'required' => count($p0) + count($p1)],
            'p0_p1_responsive_qa' => ['actual' => $qa([...$p0, ...$p1], 'responsive'), 'required' => count($p0) + count($p1)],
            'p0_p1_accessibility_qa' => ['actual' => $qa([...$p0, ...$p1], 'accessibility'), 'required' => count($p0) + count($p1)],
            'dead_css_cleanup' => ['pass' => $assets->cssGreen(), 'actual' => count($assets->deadCss), 'required' => 0],
            'dead_js_cleanup' => ['pass' => $assets->jsGreen(), 'actual' => count($assets->deadJs), 'required' => 0],
            'legacy_asset_cleanup' => ['pass' => $assets->legacyArtifacts === [], 'actual' => count($assets->legacyArtifacts), 'required' => 0],
            'asset_runtime_boundary' => ['pass' => $assets->viteBoundaryValid && $assets->canonicalRootsPresent],
        ];

        foreach (['p0_p1_functional_qa','p0_p1_visual_qa','p0_p1_responsive_qa','p0_p1_accessibility_qa'] as $key) {
            $gates[$key]['pass'] = $gates[$key]['actual'] === $gates[$key]['required'];
        }

        $blockers = [];
        foreach ($gates as $name => $gate) {
            if (($gate['pass'] ?? false) !== true) $blockers[] = $name;
        }

        return new ExperienceReleaseReport(
            status: $blockers === [] ? 'READY' : 'BLOCKED',
            gates: $gates,
            blockers: $blockers,
        );
    }
}
