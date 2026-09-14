<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

interface SalesOperationRepositoryInterface
{
    public function recordOutboundCommunication(
        string $organizationId,
        string $dealId,
        string $channel,
        string $body,
        string $externalId,
        string $idempotencyKey,
        array $metadata = [],
    ): ?string;

    public function recordInboundCommunication(
        string $organizationId,
        string $dealId,
        string $channel,
        string $sender,
        string $recipient,
        string $body,
        string $externalId,
        array $metadata = [],
    ): ?string;

    public function scheduleMeeting(
        string $organizationId,
        string $dealId,
        string $title,
        \DateTimeImmutable $scheduledAt,
        string $idempotencyKey,
        array $metadata = [],
    ): ?string;

    /**
     * Completes an existing manager activity and returns its type/title.
     * Tenant and deal checks are performed by the repository so an activity id
     * cannot be used to mutate a different organization or deal.
     *
     * @return array{activity_type:string,title:string}|null
     */
    public function completeActivity(string $organizationId, string $dealId, int $activityId, ?int $userId): ?array;

    public function rescheduleActivity(string $organizationId, string $dealId, int $activityId, \DateTimeImmutable $dueAt): bool;
}
