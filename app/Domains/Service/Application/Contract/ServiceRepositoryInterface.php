<?php
declare(strict_types=1);

namespace Domains\Service\Application\Contract;

interface ServiceRepositoryInterface
{
    /** @param array<string,mixed> $case @param array<string,mixed> $request */
    public function createRequestCase(array $case, array $request): void;

    /** @return array<string,mixed>|null */
    public function viewRequest(string $organizationId, string $requestId): ?array;

    /** @param array<string,mixed> $ticket */
    public function createTicket(array $ticket): void;

    /** @return array<string,mixed>|null */
    public function viewTicket(string $organizationId, string $ticketId): ?array;

    /** @return array<string,mixed> */
    public function lockTicket(string $organizationId, string $ticketId): array;

    public function assignTicket(
        string $organizationId,
        string $ticketId,
        string $assignmentId,
        string $assigneeId,
        string $newStatus,
        int $actorId,
    ): void;

    public function setSla(
        string $organizationId,
        string $ticketId,
        string $slaId,
        string $name,
        int $responseMinutes,
        int $resolutionMinutes,
        string $responseDueAt,
        string $resolutionDueAt,
        int $actorId,
    ): void;

    public function escalate(
        string $organizationId,
        string $ticketId,
        string $escalationId,
        int $level,
        string $reason,
        int $actorId,
    ): void;

    public function resolve(
        string $organizationId,
        string $ticketId,
        string $resolutionId,
        string $summary,
        int $actorId,
    ): void;

    public function close(string $organizationId, string $ticketId, int $actorId): void;

    public function closeCaseIfComplete(string $organizationId, string $ticketId, int $actorId): void;
}
