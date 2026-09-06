<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;
interface SalesOperationRepositoryInterface
{
    public function recordOutboundCommunication(string $organizationId,string $dealId,string $channel,string $body,string $externalId,string $idempotencyKey,array $metadata=[]):?string;
    public function recordInboundCommunication(string $organizationId,string $dealId,string $channel,string $sender,string $recipient,string $body,string $externalId,array $metadata=[]):?string;
    public function scheduleMeeting(string $organizationId,string $dealId,string $title,\DateTimeImmutable $scheduledAt,string $idempotencyKey,array $metadata=[]):?string;
}
