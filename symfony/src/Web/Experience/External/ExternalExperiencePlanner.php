<?php
declare(strict_types=1);

namespace App\Web\Experience\External;

use App\Web\Experience\Registry\PageContractRegistryInterface;
use App\Web\Experience\Registry\PageExperienceStatus;
use RuntimeException;

final readonly class ExternalExperiencePlanner
{
    private const ALLOWED = [
        'public' => ['public_catalog', 'public_detail_marketing', 'form_editor'],
        'portal' => ['portal'],
    ];

    public function __construct(private PageContractRegistryInterface $pages) {}

    public function report(): ExternalExperienceReport
    {
        $surfaces = ['public' => 0, 'portal' => 0];
        $domains = [];
        $pages = [];
        $p0 = 0;
        $p1 = 0;
        $v1Ready = 0;

        foreach ($this->pages->all() as $page) {
            if (!isset(self::ALLOWED[$page->surface])) {
                continue;
            }

            if (!in_array($page->archetype, self::ALLOWED[$page->surface], true)) {
                throw new RuntimeException(sprintf(
                    'External Experience boundary violation: %s surface %s cannot use archetype %s.',
                    $page->id->value,
                    $page->surface,
                    $page->archetype,
                ));
            }

            ++$surfaces[$page->surface];
            $domains[$page->domain] = ($domains[$page->domain] ?? 0) + 1;
            $p0 += $page->priority === 'P0' ? 1 : 0;
            $p1 += $page->priority === 'P1' ? 1 : 0;
            $v1Ready += $page->status === PageExperienceStatus::V1Ready ? 1 : 0;

            $pages[] = [
                'page_id' => $page->id->value,
                'path' => $page->path,
                'surface' => $page->surface,
                'domain' => $page->domain,
                'priority' => $page->priority,
                'status' => $page->status->value,
                'archetype' => $page->archetype,
            ];
        }

        ksort($domains);
        usort($pages, static fn (array $a, array $b): int => [$a['surface'], $a['domain'], $a['priority'], $a['page_id']] <=> [$b['surface'], $b['domain'], $b['priority'], $b['page_id']]);

        return new ExternalExperienceReport(
            pages: count($pages),
            surfaces: $surfaces,
            domains: $domains,
            pagesList: $pages,
            p0: $p0,
            p1: $p1,
            v1Ready: $v1Ready,
        );
    }
}
