<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthConversationRoutingRepositoryInterface
{
    /** @return array<string,mixed>|null */
    public function routeForResponse(string $organizationId,string $responseId):?array;

    /** @return array<string,mixed>|null */
    public function routeForClassification(string $organizationId,string $classificationId):?array;

    /** @param array<string,mixed> $route */
    public function appendRoute(array $route):void;

    public function updateRouteStatus(
        string $organizationId,string $routeId,string $status,
        ?string $referenceType=null,?string $referenceId=null,?string $errorSummary=null
    ):void;

    public function suppressContact(
        string $organizationId,string $contactId,string $sourceResponseId,string $reason
    ):void;

    public function isContactSuppressed(string $organizationId,string $contactId):bool;

    /** @return list<array<string,mixed>> */
    public function latestForCandidate(string $organizationId,string $candidateId,int $limit=20):array;
}
