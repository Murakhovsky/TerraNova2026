<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use Kernel\Execution\Contract\KernelCanonicalExecutionFenceInterface;
use Kernel\Execution\KernelExecutionClaim;
use Kernel\Agent\AgentInvocation;
use Kernel\Queue\Job;
use Kernel\Tool\Contract\ToolInterface;
use Kernel\Tool\Contract\ToolAuditInterface;
use Kernel\Tool\Contract\ToolInputValidatorInterface;
use Kernel\Tool\Contract\ToolPermissionCheckerInterface;
use Kernel\Tool\Model\ToolDefinition;
use Kernel\Tool\Model\ToolEffect;
use Kernel\Tool\Model\ToolExecution;
use Kernel\Tool\Model\ToolInvocation;
use Kernel\Tool\Model\ToolPermission;
use Kernel\Tool\Model\ToolResult;
use Kernel\Tool\Model\ToolRetryPolicy;
use Kernel\Tool\Service\ToolRegistry;
use Kernel\Tool\Service\ToolRuntime;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;

$makeTool=static function():ToolInterface {
 return new class implements ToolInterface {
    public int $calls=0;
    public function definition():ToolDefinition {
        return new ToolDefinition('ci.tool.write','Canonical retry test',[],[],ToolEffect::WRITE);
    }
    public function invoke(ToolInvocation $invocation):ToolResult {
        ++$this->calls;
        return $this->calls===1 ? ToolResult::failure('network uncertain')
            : ToolResult::success(['executed'=>true]);
    }
 };
};
$permissions=new class implements ToolPermissionCheckerInterface {
 public function allows(ToolPermission $p,ToolDefinition $d,ToolInvocation $i):bool {return true;}
};
$validator=new class implements ToolInputValidatorInterface {
 public function validate(ToolDefinition $d,ToolInvocation $i):void {}
};
$audit=new class implements ToolAuditInterface {
 public int $count=0;
 public function record(ToolExecution $e):void {$this->count++;}
};
$fence=new class implements KernelCanonicalExecutionFenceInterface {
 public bool $enabled=true;
 public int $quarantined=0;
 public int $completed=0;
 public function beginAgent(Job $j,string $a):?KernelExecutionClaim {return null;}
 public function assertAgentAdmitted(AgentInvocation $i):void {}
 public function completeAgent(KernelExecutionClaim $c,string $id):void {}
 public function beginTool(ToolInvocation $i,ToolDefinition $d,string $id):?KernelExecutionClaim {
    return $this->enabled ? new KernelExecutionClaim($i->organizationId()->value(),
        'tool-fixture','tool',str_repeat('a',32),$i->correlationId()) : null;
 }
 public function completeTool(KernelExecutionClaim $c,string $id):void {$this->completed++;}
 public function quarantine(KernelExecutionClaim $c):void {$this->quarantined++;}
};
$invoke=new ToolInvocation(OrganizationId::fromString('canonical-test'),
  'ci.tool.write',['id'=>'record'],'test-correlation',
  UserId::fromString('operator'),'stable-idempotency','approved-action');
$tool=$makeTool();
$runtime=new ToolRuntime(new ToolRegistry([$tool]),$permissions,$validator,$audit,
    new ToolRetryPolicy(4,true),$fence);
$result=$runtime->execute($invoke);
if($result->result()?->isFailure()!==true || $tool->calls!==1
    || $fence->quarantined!==1 || $fence->completed!==0 || $audit->count!==1) {
    throw new RuntimeException('Canonical Tool repeated an unknown write effect or lost quarantine.');
}
$tool=$makeTool();
$fence->enabled=false;
$legacy=new ToolRuntime(new ToolRegistry([$tool]),$permissions,$validator,$audit,
    new ToolRetryPolicy(4,true),$fence);
$second=$legacy->execute($invoke);
if($tool->calls!==2 || $second->result()?->isSuccess()!==true
    || $fence->quarantined!==1) {
    throw new RuntimeException('Legacy Tool backward compatibility changed under disabled rollout.');
}
echo "Kernel Tool safe retry contract passed: canonical max one attempt, quarantine, off-mode compatibility.\n";
