<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthBuyingCommitteeBoundary
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function discoverContact(string $organizationId,int $actorId,string $correlationId,string $accountId,string $idempotencyKey,array $input): array;

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function captureContactSnapshot(string $organizationId,int $actorId,string $correlationId,string $accountId,string $contactId,string $idempotencyKey,array $input): array;

    /** @param list<string> $requiredRoles @return array<string,mixed> */
    public function assessBuyingCommittee(string $organizationId,int $actorId,string $correlationId,string $accountId,string $idempotencyKey,array $requiredRoles): array;

    /** @return array<string,mixed> */
    public function buyingCommitteeBrief(string $organizationId,string $accountId): array;
}
