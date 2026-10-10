<?php
declare(strict_types=1);

namespace App\Web\Experience\Adaptive;

use App\Web\Experience\Action\UIAction;
use App\Web\Experience\Action\UIActionIntent;
use InvalidArgumentException;

/**
 * Versioned, deterministic presentation policy over already-authorized UIActions.
 * No SQL, API dispatch, grant of permissions, business mutation, or LLM rules.
 */
final readonly class AdaptiveExperienceResolver
{
    /**
     * @param list<UIAction> $resolvedActions From the existing UIActionResolver only.
     */
    public function compose(ExperienceContext $context, array $resolvedActions): ExperienceComposition
    {
        $visible = [];
        $collapsed = [];
        $reasons = [];
        foreach ($context->sections as $section) {
            $id = $section['id'];
            $capability = $section['capability'] ?? null;
            if ($capability !== null && !in_array($capability, $context->availableCapabilities, true)) {
                $reasons[$id] = 'capability_unavailable';
                continue;
            }
            if (($section['critical'] ?? false) === true) {
                $visible[] = $id;
                $reasons[$id] = 'critical_condition';
            } elseif (in_array($id, $context->expandedSections, true)) {
                $visible[] = $id;
                $reasons[$id] = 'explicit_expansion';
            } elseif ($section['level'] <= $context->mode->defaultLevel()) {
                $visible[] = $id;
                $reasons[$id] = 'mode_default';
            } else {
                $collapsed[] = $id;
                $reasons[$id] = 'collapsed_by_mode';
            }
        }

        $actions = [];
        foreach ($resolvedActions as $action) {
            if (!$action instanceof UIAction) {
                throw new InvalidArgumentException('Adaptive resolver only accepts canonical UIAction objects.');
            }
            if (!$action->enabled) {
                continue;
            }
            $capability = $context->actionCapabilities[$action->id] ?? null;
            if ($capability !== null && !in_array($capability, $context->availableCapabilities, true)) {
                continue;
            }
            $mutationIntents = [
                UIActionIntent::Create, UIActionIntent::Edit, UIActionIntent::Execute,
                UIActionIntent::Approve, UIActionIntent::Reject, UIActionIntent::Archive,
                UIActionIntent::Delete, UIActionIntent::Danger,
            ];
            if (in_array($action->intent, $mutationIntents, true) && $action->permission === null) {
                // Never promote a permissionless mutation to an adaptive primary action.
                continue;
            }
            $actions[$action->id] = $action; // Deduplicate mobile + desktop placements by canonical identity.
        }
        $actions = array_values($actions);
        usort($actions, static fn (UIAction $a, UIAction $b): int => $a->priority <=> $b->priority ?: strcmp($a->id, $b->id));
        $count = match ($context->mode) {
            ExperienceMode::Result => 2,
            ExperienceMode::Process => 4,
            ExperienceMode::Expert => count($actions),
        };

        return new ExperienceComposition(
            $context->mode,
            $visible,
            $collapsed,
            $reasons,
            array_slice($actions, 0, $count),
            array_slice($actions, $count),
            $context->decisionRequests,
            $context->blockingRisks,
        );
    }
}
