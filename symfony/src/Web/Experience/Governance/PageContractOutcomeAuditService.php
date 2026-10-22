<?php
declare(strict_types=1);

namespace App\Web\Experience\Governance;

use App\Web\Experience\Archetype\PageArchetypeRegistry;
use App\Web\Experience\Registry\PageContractRegistryInterface;
use App\Web\Experience\Registry\PageExperienceStatus;

/**
 * Fail-open inventory audit, fail-closed readiness: missing outcomes remain visible
 * and cannot be interpreted as verified user workflows.
 */
final readonly class PageContractOutcomeAuditService
{
    public function __construct(
        private PageContractRegistryInterface $pages,
        private PageArchetypeRegistry $archetypes,
    ) {}

    /** @return array<string,mixed> */
    public function report(): array
    {
        $rows = [];
        $counts = ['active_p0_p1' => 0, 'specific_action' => 0, 'success_condition' => 0, 'state_coverage' => 0, 'complete_contracts' => 0];
        $retired = [PageExperienceStatus::Deprecated, PageExperienceStatus::Superseded, PageExperienceStatus::Exempt];

        foreach ($this->pages->all() as $page) {
            if (in_array($page->status, $retired, true) || !in_array($page->priority, ['P0', 'P1'], true)) {
                continue;
            }
            ++$counts['active_p0_p1'];
            $action = trim((string) $page->primaryAction);
            $specific = $action !== '' && !str_ends_with($action, '.primary');
            $outcome = trim((string) $page->successCondition) !== '';
            $supportedStates = $this->archetypes->get($page->archetype)->states;
            $requiredStates = ['normal'];
            if (in_array('error', $supportedStates, true)) {
                $requiredStates[] = 'error';
            }
            if (in_array($page->archetype, ['collection', 'operational_queue', 'process_pipeline', 'map_spatial'], true)
                && in_array('empty', $supportedStates, true)) {
                $requiredStates[] = 'empty';
            }
            if ($page->surface !== 'public' && in_array('permission_denied', $supportedStates, true)) {
                $requiredStates[] = 'permission_denied';
            }
            $missingStates = array_values(array_diff($requiredStates, $page->states));
            $statesOk = $missingStates === [];

            $counts['specific_action'] += $specific ? 1 : 0;
            $counts['success_condition'] += $outcome ? 1 : 0;
            $counts['state_coverage'] += $statesOk ? 1 : 0;
            $counts['complete_contracts'] += ($specific && $outcome && $statesOk) ? 1 : 0;

            if (!$specific || !$outcome || !$statesOk) {
                $rows[] = [
                    'id' => $page->id->value,
                    'route' => $page->path,
                    'priority' => $page->priority,
                    'owner' => $page->owner,
                    'action' => $action,
                    'action_specific' => $specific,
                    'success_condition_present' => $outcome,
                    'missing_states' => $missingStates,
                    'implemented_action_verified' => false,
                ];
            }
        }

        return [
            'schema' => 1,
            'counts' => $counts,
            'contract_gaps' => $rows,
            'structurally_complete' => $counts['active_p0_p1'] === $counts['complete_contracts'],
            'implemented_actions_verified' => false,
            'human_acceptance' => false,
            'rule' => 'Structural contract completeness does not verify real controls, workflows or human acceptance.',
        ];
    }
}
