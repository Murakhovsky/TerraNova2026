<?php
declare(strict_types=1);

namespace Domains\Sales\Automation\Rule;

use Domains\Sales\Automation\Event\DealCreated;
use Domains\Sales\Automation\Event\DealStageChanged;
use Domains\Sales\Automation\Event\FollowupOverdue;
use Domains\Sales\Automation\Event\CallCompleted;
use Kernel\Rule\Rule;

final class SalesRuleCatalog
{
    /** @return list<Rule> */
    public function rules(string $organizationId): array
    {
        return [
            new Rule(
                $this->id($organizationId, 'sales-new-deal-qualification-v1'), $organizationId, 'Новий Deal потребує кваліфікації', DealCreated::TYPE,
                [
                    ['field' => 'deal.status', 'operator' => '=', 'value' => 'active'],
                    ['field' => 'deal.stage', 'operator' => '=', 'value' => 'new'],
                ],
                [
                    'type' => 'CREATE_ACTION', 'action_type' => 'sales.create_qualification_task',
                    'target_type' => 'deal', 'target_id' => '{{event.aggregate_id}}',
                    'parameters' => ['title' => 'Кваліфікувати новий запит', 'body' => 'Уточнити потребу, бюджет, термін і особу, яка приймає рішення.', 'due_in_minutes' => 240],
                    'execution_mode' => 'AUTO', 'risk_level' => 'LOW',
                ],
                priority: 10,
            ),
            new Rule(
                $this->id($organizationId, 'sales-stage-without-next-contact-v1'), $organizationId, 'Активний Deal без наступного контакту', DealStageChanged::TYPE,
                [
                    ['field' => 'deal.status', 'operator' => '=', 'value' => 'active'],
                    ['field' => 'deal.stage', 'operator' => 'IN', 'value' => ['qualification', 'need_defined', 'matching', 'viewing', 'negotiation']],
                    ['field' => 'deal.next_contact_at', 'operator' => 'IS_NULL'],
                ],
                [
                    'type' => 'CREATE_ACTION', 'action_type' => 'sales.create_followup_task',
                    'target_type' => 'deal', 'target_id' => '{{event.aggregate_id}}',
                    'parameters' => ['title' => 'Запланувати наступний контакт', 'body' => 'У Deal немає зафіксованого наступного кроку.', 'due_in_minutes' => 1440],
                    'execution_mode' => 'AUTO', 'risk_level' => 'LOW',
                ],
                priority: 20,
            ),
            new Rule(
                $this->id($organizationId, 'sales-overdue-followup-escalation-v1'), $organizationId, 'Прострочений follow-up потребує ескалації', FollowupOverdue::TYPE,
                [
                    ['field' => 'deal.status', 'operator' => '=', 'value' => 'active'],
                    ['field' => 'activity.completed_at', 'operator' => 'IS_NULL'],
                    ['field' => 'activity.is_overdue', 'operator' => '=', 'value' => true],
                ],
                [
                    'type' => 'CREATE_ACTION', 'action_type' => 'sales.escalate_overdue_followup',
                    'target_type' => 'deal', 'target_id' => '{{event.aggregate_id}}',
                    'parameters' => ['title' => 'Терміново: прострочений follow-up', 'body' => 'Попередня задача не виконана в строк. Потрібен контакт або рішення менеджера.', 'due_in_minutes' => 60],
                    'execution_mode' => 'AUTO', 'risk_level' => 'MEDIUM',
                ],
                priority: 30,
            ),
            new Rule(
                $this->id($organizationId, 'rule-call-analysis-v1'), $organizationId, 'Analyze completed sales calls', CallCompleted::TYPE,
                [],
                [
                    'type' => 'CREATE_ACTION', 'action_type' => 'agent.run.sales_intelligence',
                    'target_type' => '{{event.aggregate_type}}', 'target_id' => '{{event.aggregate_id}}',
                    'parameters' => ['question' => 'Analyze the completed call and recommend the safest high-value next sales action.'],
                    'execution_mode' => 'AUTO', 'risk_level' => 'LOW',
                ],
                priority: 10,
            ),
        ];
    }

    private function id(string $organizationId, string $code): string
    {
        return $organizationId === 'default'
            ? $code
            : substr(hash('sha256', $organizationId . ':rule:' . $code), 0, 32);
    }
}
