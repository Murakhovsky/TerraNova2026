<?php
declare(strict_types=1);

namespace Domains\Sales\Automation\Agent;

use Kernel\Agent\AgentDefinition;

final class SalesIntelligenceAgent
{
    public const NAME = 'sales_intelligence';

    public static function definition(): AgentDefinition
    {
        return new AgentDefinition(
            self::NAME,
            '2.0.0',
            'Analyze the supplied Sales context and determine deal health, risk level and reasons, opportunity, customer intent, objections, missing information, next best action and timing. '
            . 'Put these assessments in decision, reason and evidence. Never claim to execute actions or modify business data. '
            . 'Propose only allowed actions, cite concrete evidence, and lower confidence when context is incomplete.',
            'sales-intelligence-v2',
            'agent-decision-v1',
            ['sales.send_followup', 'sales.create_followup_task', 'sales.request_manager_review', 'sales.send_financing_followup'],
            'APPROVAL_REQUIRED',
            'MEDIUM',
        );
    }
}
