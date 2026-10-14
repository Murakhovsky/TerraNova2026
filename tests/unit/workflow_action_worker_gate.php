<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\ActionHandlerInterface;
use Kernel\Action\Contract\WorkflowActionWorkerAdmissionInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\Service\ActionExecutor;
use Kernel\Action\Service\ModuleActionExecutionGate;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\Contract\ActionOwningModuleInterface;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\DomainModuleInterface;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleManifest;

$handler=new class implements ActionHandlerInterface {
    public int $calls=0;
    public function supports(string $actionType):bool { return $actionType==='test.publish'; }
    public function execute(Action $action):ExecutionResult {
        ++$this->calls;
        return ExecutionResult::success(['ok'=>true]);
    }
};
$domain=new class($handler) implements DomainModuleInterface, ActionOwningModuleInterface {
    public function __construct(private ActionHandlerInterface $handler) {}
    public function name():string {return 'sales';}
    public function actionTypes():array{return ['test.publish'];}
    public function actionHandlers():array{return [$this->handler];}
};
$domains=new DomainModuleRegistry([$domain]);
$states=new class implements ModuleStateRepositoryInterface {
    public bool $enabled=true;
    public function enabledOverride(string $organizationId,string $moduleId):?bool {
        return $this->enabled;
    }
    public function setEnabled(string $organizationId,string $moduleId,bool $enabled):void {
        $this->enabled=$enabled;
    }
};
$modules=new ActiveModuleResolver(new ModuleCatalog([new ModuleManifest('sales','Sales','1.0.0')]),$states);
$worker=new class implements WorkflowActionWorkerAdmissionInterface {
    public int $calls=0;
    public bool $allowed=false;
    public function assertAuthorized(Action $action):void {
        ++$this->calls;
        if(!$this->allowed) throw new DomainException('Synthetic fail-closed worker.');
    }
};
$action=static fn(string $key):Action => new Action(
    bin2hex(random_bytes(16)),'tenant','test.publish','case','123',['test'=>true],
    'USER','owner','APPROVAL_REQUIRED','HIGH',$key,new DateTimeImmutable(),
    ActionStatus::Queued,'correlation',
);
$reject=static function(callable $call,string $purpose):void{
    try{$call();}catch(Throwable){return;}
    throw new RuntimeException('ActionExecutor bypassed worker admission: '.$purpose);
};
$without=new ActionExecutor([$handler],new ModuleActionExecutionGate($domains,$modules));
$reject(static fn()=> $without->execute($action('wf:'.str_repeat('a',64))),'missing worker adapter');
if($handler->calls!==0)throw new RuntimeException('Handler ran without Workflow last-moment gate.');
$gate=new ModuleActionExecutionGate($domains,$modules,null,$worker);
$executor=new ActionExecutor([$handler],$gate);
$reject(static fn()=> $executor->execute($action('wf:'.str_repeat('b',64))),'denied worker adapter');
if($handler->calls!==0 || $worker->calls!==1)throw new RuntimeException('Worker gate did not block handler.');
$states->enabled=false;
$worker->allowed=true;
$reject(static fn()=> $executor->execute($action('wf:'.str_repeat('c',64))),'disabled domain');
if($worker->calls!==1 || $handler->calls!==0)throw new RuntimeException('Disabled Domain bypassed module gate.');
$states->enabled=true;
$executor->execute($action('wf:'.str_repeat('d',64)));
if($worker->calls!==2 || $handler->calls!==1)throw new RuntimeException('Allowed worker was not checked before handler.');
$reject(static fn()=> $executor->execute($action('fed:'.str_repeat('e',64))),'federation must retain its own admission');
if($handler->calls!==1)throw new RuntimeException('Federation bypassed its separate worker gate.');
echo "Canonical Action worker gate passed: no adapter denied, disabled module denied, positive explicit adapter, Federation unaffected.\n";
