<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Automation\Agent;

use Kernel\Agent\AgentDefinition;

/**
 * Shared sealed recommendation-only contract for Capital Markets runtime
 * specialists. Names are separate AgentDefinitions, not prompt aliases.
 */
final class CapitalMarketsSpecialistDefinition
{
    /** @var array<string,array{0:string,1:string}> */
    private const ROLES=[
        'capital_markets_market_intelligence'=>[
            'observe','Inspect authoritative source health, quote freshness, economic instrument mappings, venue status and data quality. Identify stale, missing, delayed, contradictory or unlicensed inputs. Never invent prices or claim a trusted quote without a verified MarketState.',
        ],
        'capital_markets_opportunity'=>[
            'detect','Identify candidate dislocations ONLY from persisted, trustworthy opportunities and economic relationships. Separate observed candidates from tradable opportunities, and never invent spread, depth, fees, liquidity or an execution path.',
        ],
        'capital_markets_risk'=>[
            'assess','Explain existing deterministic opportunity risk assessments, risk envelope, portfolio concentration, liquidity, kill switches and missing evidence. Never relax risk limits, attest a missing assessment, or grant trading approval.',
        ],
        'capital_markets_execution'=>[
            'execute','Review approved paper-execution prerequisites, reservations, approved allocations, venue readiness and execution evidence. Produce a recommendation or BLOCKED decision only. NEVER place orders, reserve capital, mutate ledgers, approve proposals, or enable live trading.',
        ],
        'capital_markets_orchestrator'=>[
            'measure','Evaluate recorded paper executions, P&L attribution, quality decay and pending human decisions. Learn from recorded evidence; coordinate explanations only. You never authorize strategy promotion or order execution.',
        ],
    ];

    public static function create(string $name):AgentDefinition
    {
        $role=self::ROLES[$name]??null;
        if($role===null)throw new \InvalidArgumentException('Unknown Capital Markets specialist.');
        [$stage,$scope]=$role;
        return new AgentDefinition(
            name:$name,
            version:'1.0.0',
            systemPrompt:'You are the Capital Markets '.ucwords(str_replace('_',' ',substr($name,strlen('capital_markets_')))).
                ' Agent. '.$scope.' Base every conclusion on typed tenant-scoped evidence. '.
                'Missing, stale, untrusted or unknown data means BLOCKED, not permission to proceed. '.
                'Never claim tools were called, pretend an order executed, approve your own recommendation, '.
                'change hard risk limits, access other tenants, or emit actionable business proposals. '.
                'Output stage evidence, limitations and deterministic gate references; AI advises, engines decide.',
            promptVersion:'cm-'.$stage.'-agent-v1',
            schemaVersion:'cm-specialist-decision-v1',
            allowedActionTypes:[],
            defaultExecutionMode:'APPROVAL_REQUIRED',
            defaultRiskLevel:'HIGH',
            evidenceSchemas:[$stage=>['required'=>['findings'=>'array','limitations'=>'array']]],
            resultValidatorClass:CapitalMarketsSpecialistResultValidator::class,
            domainName:'capital_markets',
            enabled:true,
            profile:'capital_markets_'.$stage,
            confidenceThreshold:0.8,
            maxActionsPerRun:0,
            configurationManaged:false,
            outputSchema:[
                'type'=>'object',
                'required'=>['decision','reason','confidence','proposed_actions','evidence'],
                'properties'=>[
                    'decision'=>['type'=>'string','enum'=>['BLOCKED','REVIEW','NO_ACTION','PROPOSE']],
                    'reason'=>['type'=>'string'],
                    'confidence'=>['type'=>'number','minimum'=>0,'maximum'=>1],
                    'proposed_actions'=>['type'=>'array','maxItems'=>0,'items'=>['type'=>'object','additionalProperties'=>false]],
                    'evidence'=>[
                        'type'=>'object','required'=>[$stage],
                        'properties'=>[
                            $stage=>[
                                'type'=>'object','required'=>['findings','limitations','evidence_refs'],
                                'properties'=>[
                                    'findings'=>['type'=>'array','maxItems'=>20,'items'=>['type'=>'string']],
                                    'limitations'=>['type'=>'array','maxItems'=>20,'items'=>['type'=>'string']],
                                    'evidence_refs'=>['type'=>'array','maxItems'=>20,'items'=>['type'=>'string']],
                                ],
                                'additionalProperties'=>false,
                            ],
                        ],
                        'additionalProperties'=>false,
                    ],
                ],
                'additionalProperties'=>false,
            ],
        );
    }
}
