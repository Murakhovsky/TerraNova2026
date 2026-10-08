<?php
declare(strict_types=1);
namespace App\Infrastructure\Automation;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tool\Contract\ToolPermissionCheckerInterface;
use Kernel\Tool\Model\{ToolDefinition,ToolInvocation,ToolPermission};
final readonly class PortfolioAgentToolPermissionChecker implements ToolPermissionCheckerInterface
{
 private const ALLOWED=[
  'portfolio.getstate','portfolio.getexposure','portfolio.getrisk','portfolio.simulateopportunityimpact',
  'portfolio.simulateallocation','portfolio.comparescenarios','allocation.propose','risk.runstress',
  'strategy.getscorecard','performance.compare'
 ];
 public function __construct(private ActiveModuleResolver $modules){}
 public function allows(ToolPermission $permission,ToolDefinition $definition,ToolInvocation $invocation):bool
 {
  $name=$definition->name();if(!in_array($name,self::ALLOWED,true))return false;
  if($permission->name()!=='tool.'.$name.'.execute')return false;
  if(trim((string)($invocation->input()['agent_name']??''))!=='capital_markets_portfolio')return false;
  return $this->modules->isEnabled($invocation->organizationId()->value(),'capital_markets');
 }
}
