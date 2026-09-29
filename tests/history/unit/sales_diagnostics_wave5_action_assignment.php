<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use Domains\Diagnostic\Application\UseCase\AcceptDiagnosticRecommendation;
use Domains\Diagnostic\Automation\Handler\ImplementDiagnosticRecommendationActionHandler;
use Domains\Diagnostic\Model\Recommendation;
use Domains\Diagnostic\Report\RecommendationStatus;
use Kernel\Action\Action;
use Kernel\Action\ActionExecutionClaim;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\ActionExecutionGateInterface;
use Kernel\Action\Contract\ActionRepositoryInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\Service\ActionExecutor;
use Kernel\Action\Service\ActionService;

$repository=new class implements ActionRepositoryInterface {
    /** @var array<string,Action> */
    public array $items=[];

    public function save(Action $action):Action
    {
        foreach($this->items as $existing){
            if($action->idempotencyKey!==null && $existing->organizationId===$action->organizationId && $existing->idempotencyKey===$action->idempotencyKey){
                return $existing;
            }
        }
        $this->items[$action->id]=$action;
        return $action;
    }
    public function find(string $organizationId,string $id):?Action{return $this->items[$id]??null;}
    public function existsByIdempotencyKey(string $organizationId,string $key):bool
    {
        foreach($this->items as $item)if($item->organizationId===$organizationId&&$item->idempotencyKey===$key)return true;
        return false;
    }
    public function transition(string $organizationId,string $id,ActionStatus $from,ActionStatus $to):bool{return false;}
    public function claim(string $organizationId,string $id,string $workerId):?ActionExecutionClaim{return null;}
    public function claimNext(string $workerId):?ActionExecutionClaim{return null;}
    public function finish(ActionExecutionClaim $claim,ExecutionResult $result):void{}
    public function requeueStale(int $olderThanSeconds):int{return 0;}
};

$gate=new class implements ActionExecutionGateInterface {
    public function assertExecutable(Action $action):void{}
};

$recommendation=new Recommendation(
    'rec-wave5',
    ['finding.pipeline'],
    [],
    'HIGH',
    'Improve follow-up discipline',
    'medium',
    'Follow-up gaps are evidence-backed.',
    ['Create a daily overdue review'],
    ['overdue_follow_up_rate'],
    RecommendationStatus::Proposed,
    42.0,
    [
        'owner_role'=>'Sales Manager',
        'dependencies'=>['sales.pipeline'],
        'expected_outcome'=>'Reduce overdue follow-ups',
    ],
);

$dueAt=new DateTimeImmutable('2030-01-15T12:00:00+00:00');
$useCase=new AcceptDiagnosticRecommendation(new ActionService($repository,new ActionExecutor([],$gate)));
$action=$useCase->execute(
    'default',
    'diagnostic-wave5',
    $recommendation,
    1001,
    $dueAt,
    AcceptDiagnosticRecommendation::WORKFLOW,
);

if($recommendation->status!==RecommendationStatus::Accepted)throw new RuntimeException('Recommendation was not accepted.');
if($action->status!==ActionStatus::PendingApproval)throw new RuntimeException('Diagnostic Action must require approval.');
if(($action->parameters['owner_id']??null)!==1001)throw new RuntimeException('Diagnostic Action lost owner_id.');
if(($action->parameters['owner_role']??null)!=='Sales Manager')throw new RuntimeException('Diagnostic Action lost owner_role.');
if(($action->parameters['due_at']??null)!==$dueAt->format(DATE_ATOM))throw new RuntimeException('Diagnostic Action lost due_at.');
if(($action->parameters['workflow_code']??null)!==AcceptDiagnosticRecommendation::WORKFLOW)throw new RuntimeException('Diagnostic Action lost workflow_code.');

$output=(new ImplementDiagnosticRecommendationActionHandler())->execute($action);
if(!$output->successful)throw new RuntimeException('Diagnostic implementation handler failed.');
if(($output->data['owner_id']??null)!==1001)throw new RuntimeException('Action handler lost owner_id.');
if(($output->data['workflow_code']??null)!==AcceptDiagnosticRecommendation::WORKFLOW)throw new RuntimeException('Action handler lost workflow_code.');

echo "Sales Diagnostics Wave 5 action assignment contract OK\n";
