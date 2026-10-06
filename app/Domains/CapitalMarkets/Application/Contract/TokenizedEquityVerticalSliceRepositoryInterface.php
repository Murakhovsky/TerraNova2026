<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

interface TokenizedEquityVerticalSliceRepositoryInterface
{
    /** @param array<string,mixed> $payload */
    public function saveCandidate(string $organizationId,string $candidateId,string $hypothesis,string $status,array $payload):void;

    /** @param array<string,mixed> $payload */
    public function saveOpportunity(string $organizationId,string $opportunityId,string $candidateId,string $hypothesis,string $status,array $payload):void;

    /** @return array<string,mixed>|null */
    public function getOpportunity(string $organizationId,string $opportunityId):?array;

    /** @param array<string,mixed> $payload */
    public function saveRiskAssessment(string $organizationId,string $riskId,string $opportunityId,string $decision,array $payload):void;

    /** @param array<string,mixed> $payload */
    public function saveExecution(string $organizationId,string $executionId,string $opportunityId,string $status,array $payload):void;

    /** @param array<string,mixed> $payload */
    public function saveLedgerTransaction(string $organizationId,string $transactionId,string $idempotencyKey,array $payload):void;

    /** @return list<array<string,mixed>> */
    public function listOpportunities(string $organizationId,int $limit=200):array;

    /** @return array<string,mixed> */
    public function dashboard(string $organizationId):array;
}
