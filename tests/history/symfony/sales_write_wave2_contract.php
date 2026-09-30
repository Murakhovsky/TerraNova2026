<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Application\Sales\Command\AddSalesOpportunityActivityCommand;
use App\Application\Sales\Command\AddSalesOpportunityActivityCommandHandler;
use App\Application\Sales\Command\ChangeSalesOpportunityStageCommand;
use App\Application\Sales\Command\ChangeSalesOpportunityStageCommandHandler;
use App\Application\Sales\Command\ConvertSalesLeadToOpportunityCommand;
use App\Application\Sales\Command\ConvertSalesLeadToOpportunityCommandHandler;
use App\Application\Sales\Command\CreateSalesLeadCommand;
use App\Application\Sales\Command\CreateSalesLeadCommandHandler;
use App\Application\Sales\Command\ScheduleSalesNextActionCommand;
use App\Application\Sales\Command\ScheduleSalesNextActionCommandHandler;
use App\Application\Sales\Command\UpdateSalesLeadCommand;
use App\Application\Sales\Command\UpdateSalesLeadCommandHandler;
use DateTimeImmutable;
use Domains\Sales\Application\Contract\SalesWriteServiceFactoryInterface;
use Domains\Sales\Application\Contract\SalesWriteServiceInterface;
use Domains\Sales\Application\DTO\ChangeDealStageResult;
use Domains\Sales\Application\DTO\ClientCaseCommandResult;
use Domains\Sales\Application\DTO\OperationResult;
use Kernel\Shared\Domain\OrganizationId;

function wave2(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$service = new class implements SalesWriteServiceInterface {
    public array $calls = [];

    public function receivePublicLead(array $input, string $sourcePage): ClientCaseCommandResult
    {
        return ClientCaseCommandResult::success('accepted', ['lead_id' => 401]);
    }

    public function createOpportunity(array $input, int $actorId): ClientCaseCommandResult
    {
        return ClientCaseCommandResult::success('created', ['case_id' => 402]);
    }

    public function updateOpportunity(int $opportunityId, array $input, int $actorId): ClientCaseCommandResult
    {
        return ClientCaseCommandResult::success('updated', ['case_id' => $opportunityId]);
    }

    public function attachInboundRequest(int $opportunityId, int $leadId, int $actorId): ClientCaseCommandResult
    {
        return ClientCaseCommandResult::success('attached', ['case_id' => $opportunityId]);
    }

    public function updateOpportunityPropertyMatch(int $matchId, array $input, int $actorId): ClientCaseCommandResult
    {
        return ClientCaseCommandResult::success('updated', ['case_id' => 402]);
    }

    public function createLead(array $input, int $actorId, string $correlationId, string $idempotencyKey): ClientCaseCommandResult
    {
        $this->calls[] = ['create', $input, $actorId, $correlationId, $idempotencyKey];
        return ClientCaseCommandResult::success('created', ['lead_id' => 501]);
    }

    public function updateLead(int $leadId, array $input, int $actorId, string $correlationId): ClientCaseCommandResult
    {
        $this->calls[] = ['update', $leadId, $input, $actorId, $correlationId];
        return ClientCaseCommandResult::success('updated', ['case_id' => 0]);
    }

    public function convertLeadToOpportunity(int $leadId, array $input, int $actorId, string $correlationId): ClientCaseCommandResult
    {
        $this->calls[] = ['convert', $leadId, $input, $actorId, $correlationId];
        return ClientCaseCommandResult::success('created', ['case_id' => 701]);
    }

    public function addOpportunityActivity(int $opportunityId, array $input, int $actorId, string $correlationId): ClientCaseCommandResult
    {
        $this->calls[] = ['activity', $opportunityId, $input, $actorId, $correlationId];
        return ClientCaseCommandResult::success('activity_added', ['activity_id' => 801]);
    }

    public function quickUpdateOpportunity(int $opportunityId, array $input, int $actorId, string $correlationId): ClientCaseCommandResult
    {
        $this->calls[] = ['quick', $opportunityId, $input, $actorId, $correlationId];
        return ClientCaseCommandResult::success('updated', ['case_id' => $opportunityId]);
    }

    public function changeOpportunityStage(int $opportunityId, string $targetStageId, int $actorId, string $correlationId, ?string $lostReasonId = null, ?string $lostReasonNote = null): ChangeDealStageResult
    {
        $this->calls[] = ['stage', $opportunityId, $targetStageId, $actorId, $correlationId, $lostReasonId, $lostReasonNote];
        return ChangeDealStageResult::success('10', $targetStageId, true);
    }

    public function scheduleNextAction(int $opportunityId, string $title, ?string $body, DateTimeImmutable $dueAt, int $actorId, string $correlationId, string $idempotencyKey): OperationResult
    {
        $this->calls[] = ['next', $opportunityId, $title, $body, $dueAt->format(DATE_ATOM), $actorId, $correlationId, $idempotencyKey];
        return OperationResult::success('901', ['duplicate' => false]);
    }
};

$factory = new class($service) implements SalesWriteServiceFactoryInterface {
    public array $organizations = [];
    public function __construct(private SalesWriteServiceInterface $service) {}
    public function forOrganization(string $organizationId): SalesWriteServiceInterface
    {
        $this->organizations[] = $organizationId;
        return $this->service;
    }
};

$org = OrganizationId::fromString('tenant-wave2');
$actor = 77;

$create = (new CreateSalesLeadCommandHandler($factory))(new CreateSalesLeadCommand($org, $actor, 'corr-create', 'idem-create', ['full_name' => 'Lead']));
wave2($create->ok && ($create->data['lead_id'] ?? null) === 501, 'Create Lead handler did not return the canonical result.');

$update = (new UpdateSalesLeadCommandHandler($factory))(new UpdateSalesLeadCommand($org, $actor, 501, 'corr-update', ['status' => 'contacted']));
wave2($update->ok, 'Update Lead handler failed.');

$convert = (new ConvertSalesLeadToOpportunityCommandHandler($factory))(new ConvertSalesLeadToOpportunityCommand($org, $actor, 501, 'corr-convert', ['priority' => 'high']));
wave2($convert->ok && ($convert->data['case_id'] ?? null) === 701, 'Lead conversion handler failed.');

$activity = (new AddSalesOpportunityActivityCommandHandler($factory))(new AddSalesOpportunityActivityCommand($org, $actor, 701, 'corr-activity', ['activity_type' => 'note', 'title' => 'Note']));
wave2($activity->ok && ($activity->data['activity_id'] ?? null) === 801, 'Activity handler failed.');

$stage = (new ChangeSalesOpportunityStageCommandHandler($factory))(new ChangeSalesOpportunityStageCommand($org, $actor, 701, '12', 'corr-stage'));
wave2($stage->ok && ($stage->data['stage_id'] ?? null) === '12', 'Stage handler failed.');

$dueAt = new DateTimeImmutable('+1 day');
$next = (new ScheduleSalesNextActionCommandHandler($factory))(new ScheduleSalesNextActionCommand($org, $actor, 701, 'Follow-up', 'Body', $dueAt, 'corr-next', 'idem-next'));
wave2($next->ok && ($next->data['activity_id'] ?? null) === '901', 'Next Action handler failed.');

wave2($factory->organizations === array_fill(0, 6, 'tenant-wave2'), 'Every command must resolve its writer from the explicit tenant.');
wave2(($service->calls[0][4] ?? null) === 'idem-create', 'Create Lead idempotency key was not forwarded.');
wave2(($service->calls[4][4] ?? null) === 'corr-stage', 'Stage correlation id was not forwarded.');
wave2(($service->calls[5][7] ?? null) === 'idem-next', 'Next Action idempotency key was not forwarded.');

echo "Symfony Sales Wave 2 application command contract passed.\n";
