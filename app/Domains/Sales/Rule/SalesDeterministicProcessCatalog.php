<?php
declare(strict_types=1);

namespace Domains\Sales\Rule;

use Domains\Sales\Event\DealCreated;
use Domains\Sales\Event\DealStageChanged;
use Domains\Sales\Event\FollowupOverdue;
use Kernel\Rule\Rule;
use Kernel\Policy\ActionPolicy;
use Kernel\Policy\PolicyDecision;

final class SalesDeterministicProcessCatalog
{
    /** @return list<Rule> */
    public function rules(string $organizationId = 'default'): array
    {
        return [
            new Rule(
                'sales-new-deal-qualification-v1',
                $organizationId,
                'Новий Deal потребує кваліфікації',
                DealCreated::TYPE,
                [
                    ['field' => 'deal.status', 'operator' => '=', 'value' => 'active'],
                    ['field' => 'deal.stage', 'operator' => '=', 'value' => 'new'],
                ],
                [
                    'type' => 'CREATE_ACTION',
                    'action_type' => 'sales.create_qualification_task',
                    'target_type' => 'deal',
                    'target_id' => '{{event.aggregate_id}}',
                    'parameters' => [
                        'title' => 'Кваліфікувати новий запит',
                        'body' => 'Уточнити потребу, бюджет, термін і особу, яка приймає рішення.',
                        'due_in_minutes' => 240,
                    ],
                    'execution_mode' => 'AUTO',
                    'risk_level' => 'LOW',
                ],
                priority: 10,
            ),
            new Rule(
                'sales-stage-without-next-contact-v1',
                $organizationId,
                'Активний Deal без наступного контакту',
                DealStageChanged::TYPE,
                [
                    ['field' => 'deal.status', 'operator' => '=', 'value' => 'active'],
                    ['field' => 'deal.stage', 'operator' => 'IN', 'value' => ['qualification', 'need_defined', 'matching', 'viewing', 'negotiation']],
                    ['field' => 'deal.next_contact_at', 'operator' => 'IS_NULL'],
                ],
                [
                    'type' => 'CREATE_ACTION',
                    'action_type' => 'sales.create_followup_task',
                    'target_type' => 'deal',
                    'target_id' => '{{event.aggregate_id}}',
                    'parameters' => [
                        'title' => 'Запланувати наступний контакт',
                        'body' => 'У Deal немає зафіксованого наступного кроку.',
                        'due_in_minutes' => 1440,
                    ],
                    'execution_mode' => 'AUTO',
                    'risk_level' => 'LOW',
                ],
                priority: 20,
            ),
            new Rule(
                'sales-overdue-followup-escalation-v1',
                $organizationId,
                'Прострочений follow-up потребує ескалації',
                FollowupOverdue::TYPE,
                [
                    ['field' => 'deal.status', 'operator' => '=', 'value' => 'active'],
                    ['field' => 'activity.completed_at', 'operator' => 'IS_NULL'],
                    ['field' => 'activity.is_overdue', 'operator' => '=', 'value' => true],
                ],
                [
                    'type' => 'CREATE_ACTION',
                    'action_type' => 'sales.escalate_overdue_followup',
                    'target_type' => 'deal',
                    'target_id' => '{{event.aggregate_id}}',
                    'parameters' => [
                        'title' => 'Терміново: прострочений follow-up',
                        'body' => 'Попередня задача не виконана в строк. Потрібен контакт або рішення менеджера.',
                        'due_in_minutes' => 60,
                    ],
                    'execution_mode' => 'AUTO',
                    'risk_level' => 'MEDIUM',
                ],
                priority: 30,
            ),
        ];
    }

    /** @return list<ActionPolicy> */
    public function policies(string $organizationId = 'default'): array
    {
        return [
            new ActionPolicy('sales-auto-qualification-task-v1', $organizationId, 'sales.create_qualification_task', [], PolicyDecision::Auto, 10),
            new ActionPolicy('sales-auto-followup-task-v1', $organizationId, 'sales.create_followup_task', [], PolicyDecision::Auto, 10),
            new ActionPolicy('sales-auto-overdue-escalation-v1', $organizationId, 'sales.escalate_overdue_followup', [], PolicyDecision::Auto, 10),
        ];
    }
}
