<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Automation\Agent;
use Kernel\Agent\AgentDefinition;
final class CapitalMarketsPortfolioAgent
{
 public const NAME='capital_markets_portfolio';
 public static function definition():AgentDefinition
 {
  return new AgentDefinition(
   name:self::NAME,
   version:'1.0.0',
   systemPrompt:
    'You are the Capital Markets Portfolio Agent. Analyze capital allocation, concentration, correlation, idle capital, risk headroom, performance decay and capital inefficiency. '.
    'You may read portfolio/risk/performance evidence, simulate opportunity impact, compare scenarios and create allocation proposals. '.
    'You must explain why an opportunity is accepted, rejected or reduced and identify the binding risk constraint. '.
    'You cannot change hard risk limits, approve your own proposal, move live capital, disable kill switches, mutate Ledger, or execute orders. Deterministic engines are authoritative.',
   promptVersion:'cm-portfolio-agent-v1',
   schemaVersion:'cm-portfolio-agent-output-v1',
   allowedActionTypes:[],
   defaultExecutionMode:'APPROVAL_REQUIRED',
   defaultRiskLevel:'MEDIUM',
   evidenceSchemas:['portfolio'=>['required'=>['tool_requests'=>'array','findings'=>'array','limitations'=>'array']]],
   resultValidatorClass:CapitalMarketsPortfolioAgentResultValidator::class,
   domainName:'capital_markets',
   enabled:true,
   profile:'portfolio',
   confidenceThreshold:0.70,
   maxActionsPerRun:0,
   configurationManaged:false,
   outputSchema:[
    'type'=>'object','required'=>['decision','reason','confidence','proposed_actions','evidence'],
    'properties'=>[
     'decision'=>['type'=>'string'],'reason'=>['type'=>'string'],'confidence'=>['type'=>'number','minimum'=>0,'maximum'=>1],
     'proposed_actions'=>['type'=>'array','maxItems'=>0,'items'=>['type'=>'object','additionalProperties'=>false]],
     'evidence'=>['type'=>'object','required'=>['portfolio'],'properties'=>[
      'portfolio'=>['type'=>'object','required'=>['tool_requests','findings','limitations'],'properties'=>[
       'tool_requests'=>['type'=>'array','maxItems'=>6,'items'=>['type'=>'object','required'=>['name','input_json'],'properties'=>[
        'name'=>['type'=>'string','enum'=>[
         'portfolio.getstate','portfolio.getexposure','portfolio.getrisk','portfolio.simulateopportunityimpact',
         'portfolio.simulateallocation','portfolio.comparescenarios','allocation.propose','risk.runstress',
         'strategy.getscorecard','performance.compare'
        ]],
        'input_json'=>['type'=>'string'],
       ],'additionalProperties'=>false]],
       'findings'=>['type'=>'array','maxItems'=>30,'items'=>['type'=>'string']],
       'limitations'=>['type'=>'array','maxItems'=>20,'items'=>['type'=>'string']],
      ],'additionalProperties'=>false],
     ],'additionalProperties'=>false],
    ],'additionalProperties'=>false,
   ],
  );
 }
}
