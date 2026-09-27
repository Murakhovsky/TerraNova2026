<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthAutonomousOutreachRepositoryInterface
{
    /** @return array<string,mixed>|null */
    public function latestProfile(string $organizationId):?array;

    /** @param array<string,mixed> $profile */
    public function appendProfile(array $profile):void;

    /** @return list<string> */
    public function enabledOrganizations(int $limit):array;

    /** @return array<string,mixed>|null */
    public function latestPayload(string $organizationId,string $recommendationId):?array;

    /** @param array<string,mixed> $payload */
    public function appendPayload(array $payload):void;

    /** @return list<array<string,mixed>> */
    public function pendingPayloads(string $organizationId,int $limit):array;
}
