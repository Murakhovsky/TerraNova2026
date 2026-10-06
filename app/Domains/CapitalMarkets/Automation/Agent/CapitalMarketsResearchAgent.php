<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Automation\Agent;

use Kernel\Agent\AgentDefinition;

final class CapitalMarketsResearchAgent
{
    public const NAME='capital_markets_research';

    public static function definition():AgentDefinition
    {
        return new AgentDefinition(
            name:self::NAME,
            version:'1.0.0',
            systemPrompt:
                'You are the Capital Markets Research Agent. Your job is to find measurable hypotheses and strategy improvements from formal market, experiment and performance evidence. '.
                'Always distinguish observation, hypothesis, experiment design, result and decision. Search prior active, rejected and archived research before proposing a new hypothesis. '.
                'A hypothesis must include WHAT, WHY, EDGE SOURCE, MARKETS, EXPECTED BEHAVIOR, REQUIRED DATA, TEST PLAN, SUCCESS CRITERIA, FAILURE CRITERIA and KNOWN RISKS. '.
                'You may recommend research progression, but you cannot validate a strategy, override deterministic promotion gates, change risk/capital limits, edit completed results, change frozen datasets or strategy versions, or activate Live Trading. '.
                'Negative results are first-class knowledge. Never rewrite criteria after observing a bad result. AI proposes; deterministic engines test; data decides.',
            promptVersion:'cm-research-agent-v1',
            schemaVersion:'cm-research-agent-output-v1',
            allowedActionTypes:[],

            defaultExecutionMode:'APPROVAL_REQUIRED',
            defaultRiskLevel:'LOW',
            evidenceSchemas:[
                'research'=>[
                    'required'=>[
                        'summary'=>'string',
                        'hypothesis'=>'object',
                        'experiment_plan'=>'object',
                        'tool_requests'=>'array',
                        'limitations'=>'array',
                        'recommendation'=>'string',
                    ],
                ],
            ],
            resultValidatorClass:CapitalMarketsResearchAgentResultValidator::class,
            domainName:'capital_markets',
            enabled:true,
            profile:'research',
            confidenceThreshold:0.65,
            maxActionsPerRun:0,
            configurationManaged:false,
        );
    }
}
