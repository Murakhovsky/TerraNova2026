<?php
declare(strict_types=1);

namespace App\Web\Experience\Delivery;

final readonly class PageDeliveryPullRequestTemplate
{
    public function render(PageDeliveryContextPackage $package, PageDeliveryEvidenceContract $evidence): string
    {
        $c = $package->contract;
        $q = $package->qualityGate;

        return implode("\n", [
            '## COS Experience Page Delivery',
            '',
            '- Page Contract: `'.$package->pageId.'`',
            '- Route: `'.($c['route']['path'] ?? '').'`',
            '- Domain: '.($c['domain'] ?? ''),
            '- Surface: '.($c['surface'] ?? ''),
            '- Archetype: `'.($c['archetype'] ?? '').'`',
            '- Priority: '.($c['priority'] ?? ''),
            '',
            '### Product intent',
            (string) ($c['primary_goal'] ?? ''),
            '',
            '### Canonical composition',
            '- Required patterns: '.implode(', ', $c['required_patterns'] ?? []),
            '- Optional patterns: '.implode(', ', $c['optional_patterns'] ?? []),
            '- Primary action: '.((string) ($c['primary_action'] ?? 'none')),
            '- States: '.implode(', ', $c['states'] ?? []),
            '',
            '### Quality gates',
            '- Functional: required',
            '- Browser: required',
            '- Responsive: '.implode(', ', $q['responsive'] ?? []),
            '- Accessibility: '.implode(', ', $q['accessibility'] ?? []),
            '- Visual evidence: '.($q['visual_evidence'] ?? 'required'),
            '',
            '### Required evidence',
            ...array_map(static fn (string $item): string => '- [ ] '.$item, $evidence->requiredArtifacts),
            '',
            '### Human gate',
            '- [ ] Product/UX human acceptance recorded',
            '- [ ] No automated process set `human_acceptance=true`',
            '- [ ] No automated process promoted the page to `V1_READY`',
            '',
            '### Experience constraints',
            ...array_map(static fn (string $item): string => '- '.$item, $package->agentConstraints),
        ]);
    }
}
