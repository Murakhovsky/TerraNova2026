<?php
declare(strict_types=1);

namespace App\Web\Experience\Delivery;

use App\Web\Experience\Golden\GoldenExperienceSet;
use App\Web\Experience\Registry\PageContract;
use App\Web\Experience\Registry\PageContractRegistryInterface;
use RuntimeException;

final readonly class ExperienceAutonomyPolicy
{
    public function __construct(
        private PageContractRegistryInterface $pages,
        private GoldenExperienceSet $golden,
    ) {}

    public function levelFor(string $pageId): ExperienceAutonomyLevel
    {
        $page = $this->pages->get($pageId);

        if ($this->isGolden($pageId)) {
            return ExperienceAutonomyLevel::L2;
        }

        return $this->risk($page) === 'HIGH'
            ? ExperienceAutonomyLevel::L2
            : ExperienceAutonomyLevel::L3;
    }

    public function riskFor(string $pageId): string
    {
        return $this->risk($this->pages->get($pageId));
    }

    public function assertAllowed(ExperienceAutonomyLevel $level): void
    {
        if ($level === ExperienceAutonomyLevel::L4) {
            throw new RuntimeException('Experience V1 L4 auto-merge is disabled.');
        }
    }

    public function isGolden(string $pageId): bool
    {
        foreach ($this->golden->report()->pages as $page) {
            if (($page['id'] ?? null) === $pageId) {
                return true;
            }
        }

        return false;
    }

    private function risk(PageContract $page): string
    {
        $haystack = strtolower(implode(' ', [
            $page->id->value,
            $page->path,
            $page->capability,
            $page->primaryAction ?? '',
        ]));

        if (preg_match('/auth|permission|security|payment|approve|delete|settings/', $haystack)) {
            return 'HIGH';
        }

        if (preg_match('/create|edit|update|submit|deal|pipeline|workflow|manage|action/', $haystack)) {
            return 'MEDIUM';
        }

        return 'LOW';
    }
}
