<?php
declare(strict_types=1);

namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Application\Diagnostic\Command\AcceptDiagnosticRecommendationCommand;
use DateTimeImmutable;
use DomainException;
use Domains\Diagnostic\Application\Contract\DiagnosticRuntimeRepositoryInterface;
use Kernel\Action\Contract\ActionRepositoryInterface;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Tenant\Model\TenantContext;

/** Accept a recommendation and propose a Kernel Action; never approve or execute it. */
final readonly class DiagnosticRecommendationAcceptAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(
        private CommandBusInterface $commands,
        private DiagnosticRuntimeRepositoryInterface $runtime,
        private ActionRepositoryInterface $actions,
    ) {}

    public function binding(): string { return 'command:diagnostic.recommendation.accept'; }

    public function invoke(TenantContext $actor, array $input, string $key, string $correlationId): mixed
    {
        $session = $input['session_id'] ?? null;
        $recommendation = $input['recommendation_id'] ?? null;
        $owner = $input['owner_id'] ?? null;
        $due = $input['due_at'] ?? null;
        $actorId = $actor->userId()->value();
        if (count($input)!==4 || !is_string($session) || preg_match('/^[a-f0-9]{32}$/D',$session)!==1
            || !is_string($recommendation) || preg_match('/^[a-zA-Z0-9_.:-]{1,191}$/D',$recommendation)!==1
            || !is_int($owner) || $owner<1 || !is_string($due)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D',$due)!==1
            || !ctype_digit($actorId) || (int)$actorId<1 || trim($key)==='') {
            throw new DomainException('Invalid recommendation acceptance input.');
        }
        $date=DateTimeImmutable::createFromFormat(DATE_ATOM,$due);
        if ($date===false || $date->format(DATE_ATOM)!==$due) throw new DomainException('Invalid due date.');
        $org=$actor->organizationId()->value();
        $row=$this->runtime->get($org,$session);
        $before=$this->runtime->recommendation($org,$session,$recommendation);
        if ($row===null || ($row['organization_id']??null)!==$org || ($row['session_id']??null)!==$session
            || ($row['status']??null)!=='completed' || !$this->owns($before,$org,$session,$recommendation)) {
            throw new DomainException('Recommendation is not available in this completed session.');
        }
        if (empty($before['action_id']) && $date<=new DateTimeImmutable()) throw new DomainException('Due date must be in the future.');
        $workflow=AcceptDiagnosticRecommendationCommand::DEFAULT_WORKFLOW;
        $receipt=$this->commands->dispatch(new AcceptDiagnosticRecommendationCommand(
            $actor->organizationId(),(int)$actorId,$session,$recommendation,$owner,$date,$workflow,$correlationId,
        ));
        $after=$this->runtime->recommendation($org,$session,$recommendation);
        $actionId=$after['action_id']??null;
        $action=is_string($actionId)?$this->actions->find($org,$actionId):null;
        $assignment=$after['payload']['action_assignment']??[];
        if (!is_array($receipt) || !is_bool($receipt['replayed']??null) || !$this->owns($after,$org,$session,$recommendation)
            || !is_string($actionId) || preg_match('/^[a-f0-9]{32}$/D',$actionId)!==1
            || ($receipt['action_id']??null)!==$actionId || $action===null || $action->id!==$actionId
            || $action->organizationId!==$org || $action->type!=='IMPLEMENT_DIAGNOSTIC_RECOMMENDATION'
            || $action->sourceId!==$recommendation || $action->targetId!==$recommendation
            || $action->idempotencyKey!==hash('sha256',$org.':'.$session.':'.$recommendation)
            || $action->executionMode!=='APPROVAL_REQUIRED'
            || ($assignment['owner_id']??null)!==$owner || ($assignment['due_at']??null)!==$due
            || ($assignment['workflow_code']??null)!==$workflow
            || ($action->parameters['owner_id']??null)!==$owner || ($action->parameters['due_at']??null)!==$due
            || ($action->parameters['workflow_code']??null)!==$workflow
            || (!$receipt['replayed'] && (($after['status']??null)!=='ACCEPTED' || $action->status->value!=='PENDING_APPROVAL'))) {
            throw new DomainException('Recommendation acceptance cannot be verified by native readback.');
        }
        return ['session_id'=>$session,'recommendation_id'=>$recommendation,'action_id'=>$actionId,
            'action_status'=>$action->status->value,'replayed'=>$receipt['replayed']];
    }

    private function owns(?array $row,string $org,string $session,string $recommendation): bool
    {
        return $row!==null && ($row['organization_id']??null)===$org && ($row['session_id']??null)===$session
            && ($row['recommendation_id']??null)===$recommendation;
    }
}
