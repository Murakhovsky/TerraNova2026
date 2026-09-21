<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthIntelligenceBoundary
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createIcpProfile(string $organizationId,int $actorId,string $correlationId,string $idempotencyKey,array $input): array;

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function reviseIcpProfile(string $organizationId,int $actorId,string $correlationId,string $profileId,int $baseRevision,string $idempotencyKey,array $input): array;

    /** @return array<string,mixed> */
    public function activateIcpProfile(string $organizationId,int $actorId,string $correlationId,string $profileId,int $revision,string $idempotencyKey): array;

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function discoverAccount(string $organizationId,int $actorId,string $correlationId,string $idempotencyKey,array $input): array;

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function captureAccountSnapshot(string $organizationId,int $actorId,string $correlationId,string $accountId,string $idempotencyKey,array $input): array;

    /** @return array<string,mixed> */
    public function scoreAccount(string $organizationId,int $actorId,string $correlationId,string $accountId,string $profileId,int $revision,string $idempotencyKey): array;

    /** @return array<string,mixed> */
    public function accountBrief(string $organizationId,string $accountId): array;
}
