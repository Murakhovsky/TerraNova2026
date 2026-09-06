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
        if (count($actions) > 10) {
            throw new InvalidArgumentException('Agent may propose at most 10 actions.');
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
            $encodedParameters = json_encode($action['parameters'] ?? [], JSON_THROW_ON_ERROR);
            if (strlen($encodedParameters) > 32768) {
                throw new InvalidArgumentException(sprintf('Action %s parameters are too large.', $type));
            }
            foreach (['target_type', 'target_id'] as $targetField) {
                if (isset($action[$targetField]) && !is_string($action[$targetField])) {
                    throw new InvalidArgumentException(sprintf('Action %s %s must be a string.', $type, $targetField));
                }
            }
            $validated[] = [
                'type' => $type,
                'parameters' => $action['parameters'] ?? [],
                'target_type' => isset($action['target_type']) ? (string) $action['target_type'] : null,
                'target_id' => isset($action['target_id']) ? (string) $action['target_id'] : null,
            ];
        }

        $evidence = $output['evidence'] ?? [];
        if (!is_array($evidence) || count($evidence) > 20) {
            throw new InvalidArgumentException('Agent evidence must contain at most 20 items.');
        }
        foreach ($agent->evidenceSchemas as $namespace => $schema) {
            if (!isset($evidence[$namespace]) || !is_array($evidence[$namespace])) {
                throw new InvalidArgumentException('Agent evidence requires structured namespace: ' . $namespace);
            }
            foreach (($schema['required'] ?? []) as $field => $type) {
                if (!array_key_exists($field, $evidence[$namespace]) || !$this->matchesType($evidence[$namespace][$field], (string) $type)) {
                    throw new InvalidArgumentException(sprintf('Agent evidence %s.%s must be %s.', $namespace, $field, $type));
                }
            }
        }

        return new AgentResult(
            $decision,
            $reason,
            (float) $confidence,
            $validated,
            $evidence,
        );
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value) && trim($value) !== '',
            'array' => is_array($value),
            'object' => is_array($value) && !array_is_list($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            default => false,
        };
    }
}
