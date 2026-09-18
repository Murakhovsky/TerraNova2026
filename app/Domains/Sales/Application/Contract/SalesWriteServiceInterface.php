<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

use DateTimeImmutable;
use Domains\Sales\Application\DTO\ChangeDealStageResult;
use Domains\Sales\Application\DTO\ClientCaseCommandResult;
use Domains\Sales\Application\DTO\OperationResult;

interface SalesWriteServiceInterface
{
    /** @param array<string,mixed> $input */
    public function createLead(array $input, int $actorId, string $correlationId, string $idempotencyKey): ClientCaseCommandResult;

    /** @param array<string,mixed> $input */
    public function updateLead(int $leadId, array $input, int $actorId, string $correlationId): ClientCaseCommandResult;

    /** @param array<string,mixed> $input */
    public function convertLeadToOpportunity(int $leadId, array $input, int $actorId, string $correlationId): ClientCaseCommandResult;

    /** @param array<string,mixed> $input */
    public function addOpportunityActivity(int $opportunityId, array $input, int $actorId, string $correlationId): ClientCaseCommandResult;

    /** @param array<string,mixed> $input */
    public function quickUpdateOpportunity(int $opportunityId, array $input, int $actorId, string $correlationId): ClientCaseCommandResult;

    public function changeOpportunityStage(
        int $opportunityId,
        string $targetStageId,
        int $actorId,
        string $correlationId,
        ?string $lostReasonId = null,
        ?string $lostReasonNote = null,
    ): ChangeDealStageResult;

    public function scheduleNextAction(
        int $opportunityId,
        string $title,
        ?string $body,
        DateTimeImmutable $dueAt,
        int $actorId,
        string $correlationId,
        string $idempotencyKey,
    ): OperationResult;
}
