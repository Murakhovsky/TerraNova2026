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
            '1.0.0',
            'Analyze the supplied sales context. Return only the requested structured decision. '
            . 'Never claim to execute actions or modify business data. Propose only allowed actions and cite evidence from context.',
            'sales-intelligence-v1',
            'agent-decision-v1',
            ['sales.send_followup', 'sales.create_followup_task', 'sales.request_manager_review', 'sales.send_financing_followup'],
            'APPROVAL_REQUIRED',
            'MEDIUM',
        );
    }
}
