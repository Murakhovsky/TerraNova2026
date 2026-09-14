<?php
declare(strict_types=1);

namespace Domains\Sales\Automation\Rule;

use DomainException;
use Domains\Sales\Automation\Event\CallCompleted;
use Domains\Sales\Automation\Event\ClientCaseChanged;
use Domains\Sales\Automation\Event\DealCreated;
use Domains\Sales\Automation\Event\DealStageChanged;
use Domains\Sales\Automation\Event\FollowupOverdue;
use Domains\Sales\Automation\Event\LeadCreated;
use Domains\Sales\Automation\Event\SalesEventType;

final class SalesRuleDefinitionCatalog
{
    private const OPERATORS = ['=', '!=', '>', '>=', '<', '<=', 'IN', 'NOT_IN', 'EXISTS', 'NOT_EXISTS', 'IS_NULL', 'IS_NOT_NULL'];

    /** @return array<string, mixed> */
    public function catalog(): array
    {
        return [
            'triggers' => array_values($this->triggers()),
            'operators' => [
                ['value' => '=', 'label' => '='], ['value' => '!=', 'label' => '!='],
                ['value' => '>', 'label' => '>'], ['value' => '>=', 'label' => '>='],
                ['value' => '<', 'label' => '<'], ['value' => '<=', 'label' => '<='],
                ['value' => 'IN', 'label' => 'IN'], ['value' => 'NOT_IN', 'label' => 'NOT IN'],
                ['value' => 'EXISTS', 'label' => 'EXISTS'], ['value' => 'NOT_EXISTS', 'label' => 'NOT EXISTS'],
                ['value' => 'IS_NULL', 'label' => 'IS NULL'], ['value' => 'IS_NOT_NULL', 'label' => 'IS NOT NULL'],
            ],
            'durations' => [
                ['value' => 'minutes', 'label' => 'minutes'],
                ['value' => 'hours', 'label' => 'hours'],
                ['value' => 'days', 'label' => 'days'],
            ],
            'actions' => array_values($this->actions()),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function triggers(): array
    {
        $dealFacts = [
            $this->field('deal.stage_code', 'Stage', 'string'),
            $this->field('deal.pipeline_id', 'Pipeline', 'string'),
            $this->field('deal.value', 'Deal value', 'number'),
            $this->field('deal.priority', 'Priority', 'string'),
            $this->field('deal.risk', 'Risk', 'string'),
            $this->field('deal.days_without_activity', 'Days without activity', 'duration', 'days'),
            $this->field('deal.owner_id', 'Owner', 'number'),
            $this->field('deal.source', 'Source', 'string'),
            $this->field('deal.status', 'Status', 'string'),
            $this->field('deal.next_contact_at', 'Next contact', 'datetime'),
            $this->field('deal.stuck_in_stage', 'Stuck in stage', 'boolean'),
            $this->field('deal.no_activity_48h', 'No activity 48h', 'boolean'),
            $this->field('deal.high_value', 'High-value deal', 'boolean'),
            $this->field('deal.lost_reason_missing', 'Lost reason missing', 'boolean'),
        ];
        $leadFacts = [
            $this->field('lead.status', 'Lead status', 'string'),
            $this->field('lead.source', 'Lead source', 'string'),
            $this->field('lead.owner_id', 'Lead owner', 'number'),
        ];
        $activityFacts = [
            $this->field('activity.type', 'Activity type', 'string'),
            $this->field('activity.status', 'Activity status', 'string'),
            $this->field('activity.completed_at', 'Activity completed at', 'datetime'),
            $this->field('activity.is_overdue', 'Activity overdue', 'boolean'),
            ...$dealFacts,
        ];

        return [
            'deal_created' => $this->trigger('deal_created', 'Deal Created', DealCreated::TYPE, 'deal', $dealFacts),
            'deal_changed' => $this->trigger('deal_changed', 'Deal Changed', ClientCaseChanged::TYPE, 'deal', $dealFacts),
            'deal_stage_changed' => $this->trigger('deal_stage_changed', 'Deal Stage Changed', DealStageChanged::TYPE, 'deal', $dealFacts),
            'deal_won' => $this->trigger('deal_won', 'Deal Won', SalesEventType::DEAL_WON, 'deal', $dealFacts),
            'deal_lost' => $this->trigger('deal_lost', 'Deal Lost', SalesEventType::DEAL_LOST, 'deal', $dealFacts),
            'incoming_message' => $this->trigger('incoming_message', 'Incoming Message', SalesEventType::MESSAGE_RECEIVED, 'deal', $dealFacts),
            'call_completed' => $this->trigger('call_completed', 'Call Completed', CallCompleted::TYPE, 'deal', $activityFacts),
            'meeting_completed' => $this->trigger('meeting_completed', 'Meeting Completed', SalesEventType::MEETING_COMPLETED, 'deal', $activityFacts),
            'followup_overdue' => $this->trigger('followup_overdue', 'Follow-up Overdue', FollowupOverdue::TYPE, 'deal', $activityFacts),
            'no_activity_detected' => $this->trigger('no_activity_detected', 'No Activity Detected', SalesEventType::NO_ACTIVITY_DETECTED, 'deal', $dealFacts),
            'deal_stuck' => $this->trigger('deal_stuck', 'Deal Stuck', SalesEventType::DEAL_STUCK, 'deal', $dealFacts),
            'lead_created' => $this->trigger('lead_created', 'Lead Created', LeadCreated::TYPE, 'lead', $leadFacts),
            'lead_qualified' => $this->trigger('lead_qualified', 'Lead Qualified', SalesEventType::LEAD_QUALIFIED, 'lead', $leadFacts),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function actions(): array
    {
        return [
            'agent.run.sales_intelligence' => $this->action('agent.run.sales_intelligence', 'Run Sales Intelligence', ['question'], [
                'question' => 'Analyze the sales context and recommend the safest high-value next action.',
            ]),
            'sales.create_task' => $this->action('sales.create_task', 'Create Task', ['title', 'body', 'due_in_minutes'], [
                'title' => 'Sales task', 'due_in_minutes' => 240,
            ]),
            'sales.create_qualification_task' => $this->action('sales.create_qualification_task', 'Create Qualification Task', ['title', 'body', 'due_in_minutes'], [
                'title' => 'Qualify sales request', 'due_in_minutes' => 240,
            ]),
            'sales.create_followup_task' => $this->action('sales.create_followup_task', 'Create Follow-up', ['title', 'body', 'due_in_minutes'], [
                'title' => 'Follow-up', 'due_in_minutes' => 1440,
            ]),
            'sales.escalate_overdue_followup' => $this->action('sales.escalate_overdue_followup', 'Escalate Overdue Follow-up', ['title', 'body', 'due_in_minutes'], [
                'title' => 'Urgent overdue follow-up', 'due_in_minutes' => 60,
            ]),
            'sales.create_followup' => $this->action('sales.create_followup', 'Create Follow-up Schedule', ['title', 'body', 'due_at', 'due_in_minutes'], [
                'title' => 'Follow-up', 'due_in_minutes' => 1440,
            ]),
            'sales.schedule_followup' => $this->action('sales.schedule_followup', 'Schedule Follow-up', ['title', 'body', 'due_at', 'due_in_minutes'], [
                'title' => 'Follow-up', 'due_in_minutes' => 1440,
            ]),
            'sales.send_message' => $this->action('sales.send_message', 'Send Message', ['body', 'message', 'channel'], [], ['body|message']),
            'sales.request_document' => $this->action('sales.request_document', 'Request Document', ['body', 'documents', 'channel'], [], ['body|documents']),
            'sales.schedule_meeting' => $this->action('sales.schedule_meeting', 'Schedule Meeting', ['scheduled_at', 'due_at', 'title', 'location', 'channel', 'notes'], [], ['scheduled_at|due_at']),
            'sales.request_manager_review' => $this->action('sales.request_manager_review', 'Request Manager Review', ['title', 'body', 'due_in_minutes'], [
                'title' => 'Manager review', 'due_in_minutes' => 240,
            ]),
            'sales.change_stage' => $this->action('sales.change_stage', 'Change Stage', ['stage_id'], [], ['stage_id']),
            'sales.assign_owner' => $this->action('sales.assign_owner', 'Assign Owner', ['owner_id'], [], ['owner_id']),
        ];
    }

    /**
     * Accepts Basic Mode or Advanced Mode and emits the only runtime shape the Kernel receives.
     * @return array{name:string, trigger_key:string, trigger_type:string, conditions:array, actions:list<array<string,mixed>>, effect:array, priority:int}
     */
    public function normalize(array $input): array
    {
        $definition = isset($input['definition']) && is_array($input['definition']) ? $input['definition'] : $input;
        $this->assertKeys($definition, ['name','trigger_key','trigger_type','condition_mode','conditions','actions','effect','priority','version','configuration_version','code'], 'rule definition');
        if (isset($definition['effect']) && is_array($definition['effect'])) {
            $this->assertKeys($definition['effect'], ['type','actions','action_type','target_type','target_id','parameters','execution_mode','risk_level'], 'rule effect');
        }
        $name = trim((string) ($definition['name'] ?? $input['name'] ?? ''));
        if ($name === '') throw new DomainException('Rule name is required.');

        $triggerKey = trim((string) ($definition['trigger_key'] ?? $input['trigger_key'] ?? ''));
        if ($triggerKey === '' && isset($definition['trigger_type'])) {
            $triggerKey = $this->keyForCanonical((string) $definition['trigger_type']);
        }
        $triggers = $this->triggers();
        $trigger = $triggers[$triggerKey] ?? null;
        if (!is_array($trigger)) throw new DomainException('Unsupported Sales trigger.');

        $rawConditions = $definition['conditions'] ?? [];
        if (!is_array($rawConditions)) throw new DomainException('Rule conditions must be a declarative array.');
        if (array_is_list($rawConditions)) {
            $mode = strtolower((string) ($definition['condition_mode'] ?? $input['condition_mode'] ?? 'all')) === 'any' ? 'any' : 'all';
            $rawConditions = [$mode => $rawConditions];
        }
        $conditions = $this->normalizeConditions($rawConditions, $trigger);

        $rawActions = $definition['actions'] ?? null;
        if (!is_array($rawActions) && is_array($definition['effect']['actions'] ?? null)) $rawActions = $definition['effect']['actions'];
        if (!is_array($rawActions) && isset($definition['effect']['action_type'])) $rawActions = [$definition['effect']];
        if (!is_array($rawActions) || $rawActions === []) throw new DomainException('At least one Sales action is required.');
        if (!array_is_list($rawActions)) throw new DomainException('Actions must be a list.');

        $actions = [];
        foreach ($rawActions as $rawAction) {
            if (!is_array($rawAction)) throw new DomainException('Malformed Sales action.');
            $this->assertKeys($rawAction, ['type','action_type','target_type','target_id','parameters','execution_mode','risk_level'], 'Sales action');
            $type = trim((string) ($rawAction['action_type'] ?? $rawAction['type'] ?? ''));
            $allowed = $this->actions()[$type] ?? null;
            if (!is_array($allowed)) throw new DomainException('Unsupported Sales action: ' . $type);
            $parameters = is_array($rawAction['parameters'] ?? null) ? $rawAction['parameters'] : [];
            foreach (array_keys($parameters) as $key) {
                if (!in_array((string) $key, (array) $allowed['parameter_keys'], true)) {
                    throw new DomainException(sprintf('Parameter %s is not allowed for %s.', $key, $type));
                }
            }
            $parameters = [...(array) $allowed['defaults'], ...$parameters];
            foreach ((array) ($allowed['required'] ?? []) as $requirement) {
                $alternatives = explode('|', (string) $requirement);
                $present = false;
                foreach ($alternatives as $key) {
                    $value = $parameters[$key] ?? null;
                    if ((is_string($value) && trim($value) !== '') || (is_array($value) && $value !== []) || (is_int($value) && $value > 0)) {
                        $present = true;
                        break;
                    }
                }
                if (!$present) throw new DomainException(sprintf('%s requires %s.', $type, implode(' or ', $alternatives)));
            }
            $actions[] = [
                'type' => $type,
                'target_type' => $trigger['subject'],
                'target_id' => '{{event.aggregate_id}}',
                'parameters' => $parameters,
                'execution_mode' => strtoupper((string) ($rawAction['execution_mode'] ?? 'AUTO')) === 'MANUAL' ? 'MANUAL' : 'AUTO',
                'risk_level' => $this->risk((string) ($rawAction['risk_level'] ?? 'LOW')),
            ];
        }

        return [
            'name' => $this->clip($name, 220),
            'trigger_key' => $triggerKey,
            'trigger_type' => (string) $trigger['canonical'],
            'conditions' => $conditions,
            'actions' => $actions,
            'effect' => ['type' => 'CREATE_ACTION', 'actions' => $actions],
            'priority' => max(1, min(10000, (int) ($definition['priority'] ?? $input['priority'] ?? 100))),
        ];
    }

    public function keyForCanonical(string $canonical): string
    {
        foreach ($this->triggers() as $key => $trigger) {
            if (($trigger['canonical'] ?? null) === $canonical) return $key;
        }
        throw new DomainException('Unsupported Sales trigger.');
    }

    /** @param array<string,mixed> $trigger */
    private function normalizeConditions(array $conditions, array $trigger): array
    {
        $fields = [];
        foreach ((array) $trigger['fields'] as $field) $fields[(string) $field['value']] = $field;
        $walk = function (array $node) use (&$walk, $fields): array {
            if (isset($node['field'])) {
                $this->assertKeys($node, ['field','operator','value','unit'], 'condition');
                $fieldName = trim((string) ($node['field'] ?? ''));
                $field = $fields[$fieldName] ?? null;
                if (!is_array($field)) throw new DomainException('Unsupported fact for selected trigger: ' . $fieldName);
                $operator = strtoupper(str_replace(' ', '_', trim((string) ($node['operator'] ?? '='))));
                if (!in_array($operator, self::OPERATORS, true)) throw new DomainException('Unsupported condition operator: ' . $operator);
                $out = ['field' => $fieldName, 'operator' => $operator];
                if (!in_array($operator, ['EXISTS', 'NOT_EXISTS', 'IS_NULL', 'IS_NOT_NULL'], true)) {
                    $value = $node['value'] ?? null;
                    if ($operator === 'IN' || $operator === 'NOT_IN') {
                        if (is_string($value)) $value = array_values(array_filter(array_map('trim', explode(',', $value)), static fn ($v) => $v !== ''));
                        if (!is_array($value)) throw new DomainException('IN operators require a list value.');
                    }
                    if (($field['type'] ?? null) === 'duration') {
                        $value = $this->durationToDays($value, (string) ($node['unit'] ?? $field['unit'] ?? 'days'));
                    } elseif (($field['type'] ?? null) === 'number' && is_numeric($value)) {
                        $value = (float) $value;
                    } elseif (($field['type'] ?? null) === 'boolean') {
                        $value = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                        if ($value === null) throw new DomainException('Boolean condition requires true or false.');
                    }
                    $out['value'] = $value;
                }
                return $out;
            }
            if ($node === []) return [];
            $result = [];
            foreach ($node as $key => $condition) {
                if (in_array($key, ['all', 'any'], true)) {
                    if (!is_array($condition)) throw new DomainException('Condition group must be an array.');
                    $result[$key] = [];
                    foreach ($condition as $child) {
                        if (!is_array($child)) throw new DomainException('Malformed condition.');
                        $result[$key][] = $walk($child);
                    }
                    continue;
                }
                if ($key === 'not') {
                    if (!is_array($condition)) throw new DomainException('Malformed NOT condition.');
                    $result['not'] = $walk($condition);
                    continue;
                }
                throw new DomainException('Advanced rule DSL contains unsupported condition structure.');
            }
            return $result;
        };
        return $walk($conditions);
    }

    private function clip(string $value, int $limit): string
    {
        if (function_exists('mb_substr')) return mb_substr($value, 0, $limit);
        preg_match_all('/./us', $value, $characters);
        return implode('', array_slice($characters[0] ?? [], 0, $limit));
    }

    /** @param list<string> $allowed */
    private function assertKeys(array $value, array $allowed, string $subject): void
    {
        foreach (array_keys($value) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                throw new DomainException(sprintf('%s contains unsupported key: %s.', ucfirst($subject), $key));
            }
        }
    }

    private function durationToDays(mixed $value, string $unit): float
    {
        if (!is_numeric($value)) throw new DomainException('Duration must be numeric.');
        $number = (float) $value;
        return match (strtolower($unit)) {
            'minutes' => $number / 1440,
            'hours' => $number / 24,
            'days' => $number,
            default => throw new DomainException('Unsupported duration unit.'),
        };
    }

    private function risk(string $risk): string
    {
        $risk = strtoupper(trim($risk));
        return in_array($risk, ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'], true) ? $risk : 'LOW';
    }

    /** @return array<string,mixed> */
    private function trigger(string $key, string $label, string $canonical, string $subject, array $fields): array
    {
        return ['key' => $key, 'label' => $label, 'canonical' => $canonical, 'subject' => $subject, 'fields' => $fields];
    }

    /** @return array<string,mixed> */
    private function field(string $value, string $label, string $type, ?string $unit = null): array
    {
        return array_filter(['value' => $value, 'label' => $label, 'type' => $type, 'unit' => $unit], static fn ($value) => $value !== null);
    }

    /** @return array<string,mixed> */
    private function action(string $type, string $label, array $parameterKeys, array $defaults = [], array $required = []): array
    {
        return ['type' => $type, 'label' => $label, 'parameter_keys' => $parameterKeys, 'defaults' => $defaults, 'required' => $required];
    }
}
