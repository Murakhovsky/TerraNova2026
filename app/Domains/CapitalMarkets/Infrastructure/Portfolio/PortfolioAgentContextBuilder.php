<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Infrastructure\Portfolio;
use Domains\CapitalMarkets\Application\Service\CapitalRiskService;
use Domains\CapitalMarkets\Application\Contract\ResearchLabRepositoryInterface;
use Kernel\Agent\AgentInvocation;
use Kernel\Agent\Contract\AgentContextBuilderInterface;
final readonly class PortfolioAgentContextBuilder implements AgentContextBuilderInterface
{
 public function __construct(private CapitalRiskService $capitalRisk,private ResearchLabRepositoryInterface $research){}
 public function build(AgentInvocation $invocation):array
 {
  $org=$invocation->organizationId;
  return [
   'portfolio'=>$this->capitalRisk->workspace($org),
   'strategy_scorecards'=>$this->research->listScorecards($org,250),
   'subject'=>['type'=>$invocation->subjectType,'id'=>$invocation->subjectId],
   'tool_results'=>$invocation->contextReferences,
   'authority'=>[
    'hard_risk_override'=>false,'risk_envelope_mutation'=>false,'live_capital_movement'=>false,
    'self_approval'=>false,'kill_switch_disable'=>false,'ledger_mutation'=>false,
    'recommendation_only'=>true,
   ],
  ];
 }
}
