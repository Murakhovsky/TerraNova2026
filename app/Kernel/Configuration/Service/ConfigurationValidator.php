<?php
declare(strict_types=1);

namespace Kernel\Configuration\Service;

use InvalidArgumentException;
use Kernel\Module\DomainModuleInterface;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Policy\ActionPolicy;
use Kernel\Rule\Rule;

final readonly class ConfigurationValidator
{
    private const OPERATORS = [
        '=', 'EQ', '!=', 'NEQ', '>', 'GT', '>=', 'GTE', '<', 'LT', '<=', 'LTE',
        'IN', 'NOT_IN', 'IS_NULL', 'IS_NOT_NULL', 'EXISTS', 'NOT_EXISTS', 'CONTAINS',
    ];

    public function __construct(private DomainModuleRegistry $registry)
    {
    }

    /** @param list<Rule> $rules @param list<ActionPolicy> $policies */
    public function validate(DomainModuleInterface $module, string $organizationId, array $rules, array $policies): void
    {
        foreach ($rules as $rule) {
            $this->validateRule($module->name(), $organizationId, $rule);
        }
        foreach ($policies as $policy) {
            $this->validatePolicy($module->name(), $organizationId, $policy);
        }
    }

    private function validateRule(string $domain, string $organizationId, Rule $rule): void
    {
        if ($rule->organizationId !== $organizationId || !$this->registry->ownsEvent($domain, $rule->trigger)) {
            throw new InvalidArgumentException('Rule has invalid organization or event ownership: ' . $rule->id);
        }
        $this->validateConditions($rule->conditions, 'rule ' . $rule->id);
        if (($rule->effect['type'] ?? null) !== 'CREATE_ACTION') {
            throw new InvalidArgumentException('Unsupported effect in rule ' . $rule->id);
        }
        $actions = [];
        if (is_array($rule->effect['actions'] ?? null)) {
            foreach ($rule->effect['actions'] as $action) {
                if (!is_array($action) || !is_string($action['type'] ?? null)) {
                    throw new InvalidArgumentException('Malformed action in rule ' . $rule->id);
                }
                $actions[] = trim($action['type']);
            }
        } else {
            $actions[] = trim((string) ($rule->effect['action_type'] ?? ''));
        }
        if ($actions === [] || in_array('', $actions, true)) {
            throw new InvalidArgumentException('Rule has no action: ' . $rule->id);
        }
        foreach ($actions as $actionType) {
            if (str_starts_with((string) $actionType, 'agent.run.')) {
                $agent = substr((string) $actionType, strlen('agent.run.'));
                if (!$this->registry->hasAgent($agent)) {
                    throw new InvalidArgumentException('Unknown agent in rule ' . $rule->id);
                }
            } elseif (!$this->registry->ownsAction($domain, (string) $actionType)) {
                throw new InvalidArgumentException('Rule proposes an unowned action: ' . $rule->id);
            }
        }
    }

    private function validatePolicy(string $domain, string $organizationId, ActionPolicy $policy): void
    {
        if ($policy->organizationId !== $organizationId) {
            throw new InvalidArgumentException('Policy has invalid organization: ' . $policy->id);
        }
        if ($policy->actionType !== '*' && !$this->registry->ownsAction($domain, $policy->actionType)) {
            throw new InvalidArgumentException('Policy controls an unowned action: ' . $policy->id);
        }
        $this->validateConditions($policy->conditions, 'policy ' . $policy->id);
    }

    private function validateConditions(array $conditions, string $subject): void
    {
        foreach ($conditions as $key => $condition) {
            if (in_array($key, ['all', 'any'], true)) {
                if (!is_array($condition)) throw new InvalidArgumentException('Invalid condition group in ' . $subject);
                $this->validateConditions($condition, $subject);
                continue;
            }
            if ($key === 'not') {
                if (!is_array($condition)) throw new InvalidArgumentException('Invalid negation in ' . $subject);
                $this->validateConditions([$condition], $subject);
                continue;
            }
            if (!is_array($condition) || trim((string) ($condition['field'] ?? '')) === '') {
                throw new InvalidArgumentException('Condition field is required in ' . $subject);
            }
            $operator = strtoupper((string) ($condition['operator'] ?? '='));
            if (!in_array($operator, self::OPERATORS, true)) {
                throw new InvalidArgumentException('Unsupported condition operator in ' . $subject . ': ' . $operator);
            }
        }
    }
}
