<?php
declare(strict_types=1);

namespace Domains\Sales\Automation\Agent;

use Kernel\Agent\AgentDefinition;

final class SalesIntelligenceAgent
{
    public const NAME = 'sales_intelligence';
    public const MAX_ACTIONS_PER_RUN = 8;
    public const DEFAULT_PROFILE = 'balanced';
    public const DEFAULT_CONFIDENCE_THRESHOLD = 0.65;

    public const CONTEXT_SOURCES = [
        'deal', 'person', 'lead', 'pipeline', 'activities', 'communications', 'last_contact',
        'next_action', 'sales_history', 'assigned_manager', 'product_or_property', 'metrics',
        'rules', 'policies', 'goals',
    ];

    public const HARD_ALLOWED_ACTIONS = [
        'sales.create_task',
        'sales.create_followup',
        'sales.schedule_followup',
        'sales.send_message',
        'sales.change_stage',
        'sales.request_document',
        'sales.schedule_meeting',
        'sales.request_manager_review',
    ];

    public static function definition(): AgentDefinition
    {
        return new AgentDefinition(
            self::NAME,
            '3.1.0',
            'Analyze the supplied Sales context and determine deal health, risk level and reasons, opportunity, customer intent, objections, missing information, next best action and timing. '
            . 'Return the explicit assessment only in evidence.sales_intelligence with deal_health, risk_level, risk_reasons, opportunity_level, customer_intent, objections, missing_information, next_best_action and recommended_timing. '
            . 'Never execute actions, modify business data, or present missing information as fact. Propose only allowed actions and lower confidence when context is incomplete.',
            'sales-intelligence-v3.1',
            'agent-decision-v1',
            self::HARD_ALLOWED_ACTIONS,
            'APPROVAL_REQUIRED',
            'MEDIUM',
            ['sales_intelligence' => ['required' => [
                'deal_health' => 'string',
                'risk_level' => 'string',
                'risk_reasons' => 'array',
                'opportunity_level' => 'string',
                'customer_intent' => 'string',
                'objections' => 'array',
                'missing_information' => 'array',
                'recommended_timing' => 'string',
                'next_best_action' => 'string_or_object',
            ]]],
            SalesIntelligenceResultValidator::class,
            'sales',
            true,
            self::DEFAULT_PROFILE,
            null,
            self::CONTEXT_SOURCES,
            self::DEFAULT_CONFIDENCE_THRESHOLD,
            self::MAX_ACTIONS_PER_RUN,
            true,
        );
    }

    public static function systemContract(): array
    {
        $definition = self::definition();
        return [
            'agent_name' => $definition->name,
            'agent_version' => $definition->version,
            'prompt_version' => $definition->promptVersion,
            'system_prompt_hash' => hash('sha256', $definition->systemPrompt),
            'schema_version' => $definition->schemaVersion,
            'hard_allowed_actions' => $definition->allowedActionTypes,
            'max_actions_per_run' => $definition->maxActionsPerRun,
            'evidence_schema' => $definition->evidenceSchemas,
            'result_validator' => $definition->resultValidatorClass,
            'default_execution_mode' => $definition->defaultExecutionMode,
            'default_risk_level' => $definition->defaultRiskLevel,
        ];
    }

    public static function defaultRuntimeConfiguration(): array
    {
        $definition = self::definition();
        return [
            'enabled' => true,
            'profile' => $definition->profile,
            'model' => $definition->model,
            'business_instructions' => '',
            'context_sources' => $definition->contextSources ?? [],
            'allowed_actions' => $definition->allowedActionTypes,
            'confidence_threshold' => $definition->confidenceThreshold,
        ];
    }
}
