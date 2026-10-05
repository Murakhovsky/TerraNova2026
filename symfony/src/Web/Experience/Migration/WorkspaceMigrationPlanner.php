<?php
declare(strict_types=1);

namespace App\Web\Experience\Migration;

use App\Web\Experience\Golden\GoldenExperienceSet;
use App\Web\Experience\Delivery\ExperienceAutonomyPolicy;
use App\Web\Experience\Registry\PageContract;
use App\Web\Experience\Registry\PageContractRegistryInterface;
use App\Web\Experience\Registry\PageExperienceStatus;

final readonly class WorkspaceMigrationPlanner
{
    private const ORDER = [
        'Sales',
        'Growth',
        'Property',
        'Diagnostic/Core',
        'Service',
        'RealEstate',
        'Admin',
    ];

    /** @var list<string> */
    private const GOLDEN = [
        'core.executive.dashboard',
        'sales.dashboard',
        'sales.today',
        'sales.leads.collection',
        'sales.deal.workspace',
        'sales.pipeline',
        'growth.overview',
        'property.map',
    ];

    public function __construct(
        private PageContractRegistryInterface $pages,
        private GoldenExperienceSet $golden,
        private ExperienceAutonomyPolicy $autonomy,
    ) {}

    public function plan(): WorkspaceMigrationPlan
    {
        $golden = $this->golden->report();
        $buckets = array_fill_keys(self::ORDER, []);

        foreach ($this->pages->all() as $page) {
            if (!$this->eligible($page)) {
                continue;
            }

            $stage = $this->stage($page);
            if ($stage === null) {
                continue;
            }

            $buckets[$stage][] = $this->row($page);
        }

        $stages = [];
        $total = 0;
        foreach (self::ORDER as $index => $stage) {
            $items = $buckets[$stage];
            usort($items, static function (array $a, array $b): int {
                $priority = ['P0' => 0, 'P1' => 1, 'P2' => 2, 'P3' => 3];
                return [$priority[$a['priority']] ?? 9, $a['page_id']]
                    <=> [$priority[$b['priority']] ?? 9, $b['page_id']];
            });
            $total += count($items);
            $stages[] = [
                'sequence' => $index + 1,
                'name' => $stage,
                'pages' => $items,
                'count' => count($items),
            ];
        }

        return new WorkspaceMigrationPlan(
            blocked: $golden->massMigrationBlocked,
            blockedReason: $golden->massMigrationBlocked
                ? 'Golden Experience human acceptance is incomplete.'
                : '',
            stages: $stages,
            pages: $total,
        );
    }

    private function eligible(PageContract $page): bool
    {
        if (!in_array($page->surface, ['workspace', 'system'], true)) {
            return false;
        }

        if (in_array($page->id->value, self::GOLDEN, true)) {
            return false;
        }

        return !in_array($page->status, [
            PageExperienceStatus::V1Ready,
            PageExperienceStatus::Deprecated,
            PageExperienceStatus::Superseded,
            PageExperienceStatus::Exempt,
        ], true);
    }

    private function stage(PageContract $page): ?string
    {
        return match ($page->domain) {
            'Sales' => 'Sales',
            'Growth' => 'Growth',
            'Property' => 'Property',
            'Diagnostic', 'Core' => 'Diagnostic/Core',
            'Service' => 'Service',
            'RealEstate' => 'RealEstate',
            'Admin', 'Platform', 'Architecture', 'Engineering', 'Identity', 'Operations', 'AI', 'Content', 'Spatial' => 'Admin',
            default => null,
        };
    }

    /** @return array<string,mixed> */
    private function row(PageContract $page): array
    {
        return [
            'page_id' => $page->id->value,
            'route' => $page->routeName,
            'path' => $page->path,
            'domain' => $page->domain,
            'surface' => $page->surface,
            'priority' => $page->priority,
            'status' => $page->status->value,
            'archetype' => $page->archetype,
            'risk' => $this->autonomy->riskFor($page->id->value),
            'autonomy' => $this->autonomy->levelFor($page->id->value)->value,
        ];
    }

}
