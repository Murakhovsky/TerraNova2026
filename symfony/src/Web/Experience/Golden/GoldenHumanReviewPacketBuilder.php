<?php
declare(strict_types=1);

namespace App\Web\Experience\Golden;

final readonly class GoldenHumanReviewPacketBuilder
{
    public function __construct(
        private GoldenExperienceSet $golden,
        private GoldenStructureAuditService $structure,
    ) {}

    /** @return array<string,mixed> */
    public function build(): array
    {
        $golden = $this->golden->report();
        $structure = $this->structure->audit();
        $structureById = [];
        foreach ($structure->pages as $page) {
            $structureById[$page['id']] = $page;
        }

        $pages = [];
        foreach ($golden->pages as $page) {
            $pages[] = [
                ...$page,
                'structure' => $structureById[$page['id']] ?? null,
                'review_criteria' => [
                    'purpose_is_clear',
                    'information_hierarchy_is_clear',
                    'primary_action_is_obvious',
                    'first_viewport_explains_business_state',
                    'next_actions_are_clear',
                    'typography_and_components_are_consistent',
                    'normal_loading_empty_error_states_are_credible',
                    'desktop_and_mobile_are_usable',
                    'keyboard_usage_is_credible',
                    'visual_quality_is_reference_level',
                    'content_is_clear_and_product_appropriate',
                ],
                'decision' => $page['humanDecision']['decision'] ?? 'PENDING',
                'decision_actor' => $page['humanDecision']['actor'] ?? null,
                'decision_at' => $page['humanDecision']['decided_at'] ?? null,
                'decision_note' => $page['humanDecision']['note'] ?? null,
            ];
        }

        return [
            'title' => 'COS Golden Experience Human Review',
            'automated_gate' => [
                'golden_structure' => $structure->isGreen() ? 'PASS' : 'FAIL',
                'browser_qa' => 'Verify latest CI Golden Eight browser QA is PASS.',
                'human_acceptance_is_automated' => false,
            ],
            'decision_options' => ['ACCEPT','REQUEST_CHANGES'],
            'pages' => $pages,
            'rule' => 'Only a human product/UX decision may set human acceptance or authorize V1_READY promotion.',
        ];
    }

    public function markdown(): string
    {
        $packet = $this->build();
        $lines = [
            '# COS Golden Experience Human Review',
            '',
            '> Automated QA does not equal product acceptance. Review all eight reference pages.',
            '',
            '## Global automated gate',
            '',
            '- Golden structure: **'.$packet['automated_gate']['golden_structure'].'**',
            '- Golden Eight browser QA: verify latest CI run is **PASS**',
            '- Human acceptance is never set automatically.',
            '',
        ];

        foreach ($packet['pages'] as $index => $page) {
            $lines[] = '## '.($index + 1).'. '.$page['id'];
            $lines[] = '';
            $lines[] = '- Route: '.$page['path'];
            $lines[] = '- Domain: '.$page['domain'];
            $lines[] = '- Archetype: '.$page['archetype'];
            $lines[] = '- Current status: '.$page['status'];
            $lines[] = '- Structural gate: **'.(($page['structure']['passed'] ?? false) ? 'PASS' : 'FAIL').'**';
            $lines[] = '';
            $lines[] = 'Review:';
            foreach ($page['review_criteria'] as $criterion) {
                $lines[] = '- [ ] '.$criterion;
            }
            $lines[] = '';
            $lines[] = 'Decision: **'.$page['decision'].'**';
            if ($page['decision_actor'] !== null) {
                $lines[] = '- Actor: '.$page['decision_actor'];
            }
            if ($page['decision_at'] !== null) {
                $lines[] = '- Decided at: '.$page['decision_at'];
            }
            if ($page['decision_note'] !== null) {
                $lines[] = '- Note: '.$page['decision_note'];
            }
            $lines[] = '';
        }

        $lines[] = '## Release rule';
        $lines[] = '';
        $lines[] = 'EX-005 Workspace Mass Migration remains blocked until Golden 8 human acceptance is complete.';
        $lines[] = '';

        return implode("\n", $lines);
    }
}
