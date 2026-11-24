<?php
declare(strict_types=1);

use App\Application\Capability\CanonicalCapabilityGateway;
use App\Application\Diagnostic\Command\AcceptDiagnosticRecommendationCommand;
use App\Infrastructure\Capability\DiagnosticRecommendationAcceptAdapter;
use App\Persistence\Federation\FederationCapabilityBindingResolver;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Action\ActionExecutionClaim;
use Kernel\Action\ExecutionResult;
use Kernel\Action\Contract\ActionRepositoryInterface;
use Domains\Diagnostic\Application\Contract\DiagnosticRuntimeRepositoryInterface;
use Kernel\Application\Command\CommandInterface;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDefinition;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
spl_autoload_register(static function (string $name) use ($root): void {
    if (!str_starts_with($name, 'App\\')) return;
    $file = $root . '/symfony/src/' . str_replace('\\', '/', substr($name, 4)) . '.php';
    if (is_file($file)) require $file;
});
$module = require $root . '/app/Domains/Diagnostic/module.php';
$moduleCatalog = new ModuleCatalog([ModuleDefinition::fromArray($module)]);
$catalog = new CanonicalCapabilityCatalog($moduleCatalog);
$state = new class implements ModuleStateRepositoryInterface {
    public bool $enabled = true;
    public function enabledOverride(string $org, string $module): ?bool { return $this->enabled; }
    public function setEnabled(string $org, string $module, bool $enabled): void { $this->enabled = $enabled; }
};
$active = new ActiveModuleResolver($moduleCatalog, $state);
$resolver = new FederationCapabilityBindingResolver($catalog, new DomainModuleRegistry([]), $active);
$repo = new class implements DiagnosticRuntimeRepositoryInterface {
    public array $rows = [];
    public array $reports = [];
    public array $recommendationRows = [];
    public function get(string $organizationId, string $sessionId): ?array {
        return $this->rows[$organizationId . ':' . $sessionId] ?? null;
    }
    public function report(string $organizationId, string $sessionId): ?array {
        return $this->reports[$organizationId . ':' . $sessionId] ?? null;
    }
    public function create(string $organizationId, string $sessionId, string $mode, array $state, ?string $parentSessionId, DateTimeImmutable $at): void { throw new LogicException('Unexpected native mutation.'); }
    public function saveState(string $organizationId, string $sessionId, array $state, ?string $currentQuestionId, int $revision): void { throw new LogicException('Unexpected native mutation.'); }
    public function complete(string $organizationId, string $sessionId, DateTimeImmutable $at): void { throw new LogicException('Unexpected native mutation.'); }
    public function saveReport(string $organizationId, string $sessionId, array $report, int $stateRevision, DateTimeImmutable $at): int { throw new LogicException('Unexpected native mutation.'); }
    public function saveRecommendation(string $organizationId, string $sessionId, string $recommendationId, array $payload, string $status, DateTimeImmutable $at, ?string $actionId = null): void { throw new LogicException('Unexpected native mutation.'); }
    public function recommendation(string $organizationId, string $sessionId, string $recommendationId): ?array { return $this->recommendationRows[$organizationId.':'.$sessionId.':'.$recommendationId] ?? null; }
    public function recommendations(string $organizationId, string $sessionId): array { throw new LogicException('Unexpected native mutation.'); }
    public function appendMeasurement(string $organizationId, string $sessionId, string $actionId, string $metricCode, float $value, ?string $evidenceId, DateTimeImmutable $at): void { throw new LogicException('Unexpected native mutation.'); }
    public function measurements(string $organizationId, string $sessionId): array { throw new LogicException('Unexpected native mutation.'); }
    public function schedule(string $organizationId, string $sourceSessionId, int $intervalDays, DateTimeImmutable $dueAt, DateTimeImmutable $at): array { throw new LogicException('Unexpected native mutation.'); }
    public function dueSchedules(string $organizationId, DateTimeImmutable $at, int $limit = 100): array { throw new LogicException('Unexpected native mutation.'); }
    public function pendingScheduleForSource(string $organizationId, string $sourceSessionId): ?array { throw new LogicException('Unexpected native mutation.'); }
    public function markScheduleStarted(string $organizationId, string $scheduleId, string $followupSessionId, DateTimeImmutable $at): void { throw new LogicException('Unexpected native mutation.'); }
};
$actions = new class implements ActionRepositoryInterface {
    public array $items=[];
    public function save(Action $action): Action { throw new LogicException('Adapter must not write Actions.'); }
    public function find(string $organizationId,string $id): ?Action { return $this->items[$organizationId.':'.$id] ?? null; }
    public function existsByIdempotencyKey(string $organizationId,string $key): bool { return false; }
    public function transition(string $organizationId,string $id,ActionStatus $from,ActionStatus $to): bool { throw new LogicException('No approval or execution allowed.'); }
    public function claim(string $organizationId,string $id,string $workerId): ?ActionExecutionClaim { throw new LogicException('No claim allowed.'); }
    public function claimNext(string $workerId): ?ActionExecutionClaim { throw new LogicException('No claim allowed.'); }
    public function finish(ActionExecutionClaim $claim,ExecutionResult $result): void { throw new LogicException('No execution allowed.'); }
    public function requeueStale(int $olderThanSeconds=300): int { throw new LogicException('No queue allowed.'); }
};
$bus = new class($repo,$actions) implements CommandBusInterface {
    public array $calls=[];
    public bool $skipWrite=false;
    public bool $badReceipt=false;
    public function __construct(private object $repo,private object $actions) {}
    public function dispatch(CommandInterface $command): mixed {
        if (!$command instanceof AcceptDiagnosticRecommendationCommand) throw new LogicException('Wrong command.');
        $this->calls[]=$command;
        $org=$command->organizationId->value();
        $key=$org.':'.$command->sessionId.':'.$command->recommendationId;
        $row=$this->repo->recommendationRows[$key];
        $assignment=['owner_id'=>$command->ownerId,'owner_role'=>'Manager','due_at'=>$command->dueAt->format(DATE_ATOM),'workflow_code'=>$command->workflowCode];
        $replayed=!empty($row['action_id']);
        $id=$row['action_id']??str_repeat('c',32);
        if ($replayed && $row['payload']['action_assignment']!==$assignment) throw new DomainException('Conflicting assignment.');
        if (!$replayed && !$this->skipWrite) {
            $this->repo->recommendationRows[$key]=[...$row,'action_id'=>$id,'status'=>'ACCEPTED','payload'=>['action_assignment'=>$assignment,'private'=>'hidden']];
            $this->actions->items[$org.':'.$id]=new Action($id,$org,'IMPLEMENT_DIAGNOSTIC_RECOMMENDATION','diagnostic_recommendation',$command->recommendationId,$assignment,'DIAGNOSTIC_RECOMMENDATION',$command->recommendationId,'APPROVAL_REQUIRED','MEDIUM',hash('sha256',$org.':'.$command->sessionId.':'.$command->recommendationId),new DateTimeImmutable(),ActionStatus::PendingApproval);
        }
        return ['action_id'=>$this->badReceipt?str_repeat('d',32):$id,'replayed'=>$replayed,'recommendation'=>['private'=>'hidden']];
    }
};
$gateway=new CanonicalCapabilityGateway($catalog,$active,$resolver,[new DiagnosticRecommendationAcceptAdapter($bus,$repo,$actions)]);
$actor=static fn(string $org,string $role='manager')=>new TenantContext(UserId::fromString('31'),OrganizationId::fromString($org),OrganizationRole::fromString($role),[Permission::fromString(TenantPermissions::ACCESS),Permission::fromString(TenantPermissions::MANAGE)]);
$manager=$actor('tenant-a');
$reject=static function(callable $call):void {
    try {$call();} catch (DomainException) {return;}
    throw new RuntimeException('Unsafe acceptance was allowed.');
};
$check=static function(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);};
$sid=str_repeat('a',32);
$rid='rec-1';
$row=['organization_id'=>'tenant-a','session_id'=>$sid,'recommendation_id'=>$rid,'status'=>'PROPOSED','action_id'=>null,'payload'=>[]];
$repo->rows['tenant-a:'.$sid]=['organization_id'=>'tenant-a','session_id'=>$sid,'status'=>'completed'];
$repo->recommendationRows['tenant-a:'.$sid.':'.$rid]=$row;
$input=['session_id'=>$sid,'recommendation_id'=>$rid,'owner_id'=>44,'due_at'=>(new DateTimeImmutable('+30 days'))->format(DATE_ATOM)];
$cap='diagnostic.recommendation.accept';
$call=static fn(array $i,string $origin='human_api',string $key='key')=>$gateway->invoke($manager,$cap,$i,$origin,$key,'trace');
$check($gateway->describe($manager,$cap)['status']==='direct_human','Must be human only.');
$reject(fn()=> $gateway->invoke($actor('tenant-b'),$cap,$input,'human_api','key','trace'));
$reject(fn()=> $gateway->invoke($actor('tenant-a','viewer'),$cap,$input,'human_api','key','trace'));
$reject(fn()=> $call($input,'ai'));
$reject(fn()=> $call($input,'cron'));
$reject(fn()=> $call($input,'human_api',''));
$reject(fn()=> $call([...$input,'tenant_id'=>'tenant-b']));
$reject(fn()=> $call([...$input,'due_at'=>'2030-02-30T12:00:00+00:00']));
$reject(fn()=> $call([...$input,'due_at'=>'2000-01-01T12:00:00+00:00']));
$check(count($bus->calls)===0,'Rejected input reached writer.');
$receipt=$call($input);
$expected=['session_id'=>$sid,'recommendation_id'=>$rid,'action_id'=>str_repeat('c',32),'action_status'=>'PENDING_APPROVAL','replayed'=>false];
$check($receipt===$expected,'Readback mismatch or unredacted receipt.');
$check($call($input,'human_ui','different-key')===[...$expected,'replayed'=>true] && count($actions->items)===1,'Replay created a new action.');
$reject(fn()=> $call([...$input,'owner_id'=>45]));
$bus->badReceipt=true;
$reject(fn()=> $call($input));
$bus->badReceipt=false;
$repo->recommendationRows['tenant-a:'.$sid.':'.$rid]=$row;
$bus->skipWrite=true;
$reject(fn()=> $call($input));
$state->enabled=false;
$reject(fn()=> $call($input));
$check(count($bus->calls)===5,'Unexpected writer calls.');
echo "Diagnostic recommendation acceptance and native approval Action readback PASS.\n";
