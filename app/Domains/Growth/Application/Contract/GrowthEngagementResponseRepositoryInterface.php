<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthEngagementResponseRepositoryInterface
{
    /** @param array<string,mixed> $response @return array<string,mixed> */
    public function recordOrVerify(array $response):array;

    /** @return array<string,mixed>|null */
    public function byId(string $organizationId,string $responseId):?array;

    /** @return array<string,mixed> */
    public function lockById(string $organizationId,string $responseId):array;

    /** @return list<array<string,mixed>> */
    public function latestForCandidate(string $organizationId,string $candidateId,int $limit=20):array;

    /** @return array<string,mixed>|null */
    public function latestClassification(string $organizationId,string $responseId):?array;

    /** @return array<string,mixed>|null */
    public function classificationForVersion(string $organizationId,string $responseId,string $promptVersion,string $schemaVersion):?array;

    /** @param array<string,mixed> $classification */
    public function appendClassification(array $classification):void;
}
