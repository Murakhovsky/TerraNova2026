<?php
declare(strict_types=1);

namespace Domains\Service\Application\Contract;

interface ServiceApplicationBoundary
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createRequest(string $organizationId, int $actorId, string $correlationId, string $idempotencyKey, array $input): array;

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createTicket(string $organizationId, int $actorId, string $correlationId, string $requestId, string $idempotencyKey, array $input): array;

    /** @return array<string,mixed> */
    public function assignTicket(string $organizationId, int $actorId, string $correlationId, string $ticketId, string $assigneeId, string $idempotencyKey): array;

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function setSla(string $organizationId, int $actorId, string $correlationId, string $ticketId, string $idempotencyKey, array $input): array;

    /** @return array<string,mixed> */
    public function escalate(string $organizationId, int $actorId, string $correlationId, string $ticketId, string $reason, string $idempotencyKey): array;

    /** @return array<string,mixed> */
    public function resolve(string $organizationId, int $actorId, string $correlationId, string $ticketId, string $summary, string $idempotencyKey): array;

    /** @return array<string,mixed> */
    public function close(string $organizationId, int $actorId, string $correlationId, string $ticketId, string $idempotencyKey): array;

    /** @return array<string,mixed>|null */
    public function viewRequest(string $organizationId, string $requestId): ?array;

    /** @return array<string,mixed>|null */
    public function viewTicket(string $organizationId, string $ticketId): ?array;
}
