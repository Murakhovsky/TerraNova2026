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
            '3.0.0',
            'Analyze the supplied Sales context and determine deal health, risk level and reasons, opportunity, customer intent, objections, missing information, next best action and timing. '
            . 'Return the explicit assessment only in evidence.sales_intelligence with deal_health, risk_level, risk_reasons, opportunity_level, customer_intent, objections, missing_information, next_best_action and recommended_timing. '
            . 'Never execute actions, modify business data, or present missing information as fact. Propose only allowed actions and lower confidence when context is incomplete.',
            'sales-intelligence-v3',
            'agent-decision-v1',
            ['sales.create_task','sales.create_followup','sales.schedule_followup','sales.send_message','sales.change_stage','sales.request_document','sales.schedule_meeting','sales.request_manager_review'],
            'APPROVAL_REQUIRED',
            'MEDIUM',
            ['sales_intelligence'=>['required'=>[
                'deal_health'=>'string','risk_level'=>'string','risk_reasons'=>'array','opportunity_level'=>'string',
                'customer_intent'=>'string','objections'=>'array','missing_information'=>'array','recommended_timing'=>'string',
            ]]],
        );
    }
}
