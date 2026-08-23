<?php
declare(strict_types=1);

namespace Kernel\Agent\Service;

use InvalidArgumentException;
use Kernel\Agent\AgentDefinition;
use Kernel\Agent\AgentResult;

final class StructuredDecisionValidator
{
    public function validate(array $output, AgentDefinition $agent): AgentResult
    {
        $decision = trim((string) ($output['decision'] ?? ''));
        $reason = trim((string) ($output['reason'] ?? ''));
        $confidence = $output['confidence'] ?? null;
        $actions = $output['proposed_actions'] ?? null;

        if ($decision === '' || $reason === '') {
            throw new InvalidArgumentException('Agent output requires decision and reason.');
        }
        if (!is_numeric($confidence) || (float) $confidence < 0 || (float) $confidence > 1) {
            throw new InvalidArgumentException('Agent confidence must be between 0 and 1.');
        }
        if (!is_array($actions)) {
            throw new InvalidArgumentException('Agent proposed_actions must be an array.');
        }

        $validated = [];
        foreach ($actions as $index => $action) {
            if (!is_array($action) || !is_string($action['type'] ?? null)) {
                throw new InvalidArgumentException(sprintf('Invalid proposed action at index %d.', $index));
            }
            $type = trim($action['type']);
            if (!in_array($type, $agent->allowedActionTypes, true)) {
                throw new InvalidArgumentException(sprintf('Agent is not allowed to propose %s.', $type));
            }
            if (isset($action['parameters']) && !is_array($action['parameters'])) {
                throw new InvalidArgumentException(sprintf('Action %s parameters must be an object.', $type));
            }
            $validated[] = [
                'type' => $type,
                'parameters' => $action['parameters'] ?? [],
                'target_type' => isset($action['target_type']) ? (string) $action['target_type'] : null,
                'target_id' => isset($action['target_id']) ? (string) $action['target_id'] : null,
            ];
        }

        return new AgentResult(
            $decision,
            $reason,
            (float) $confidence,
            $validated,
            is_array($output['evidence'] ?? null) ? $output['evidence'] : [],
        );
    }
}
