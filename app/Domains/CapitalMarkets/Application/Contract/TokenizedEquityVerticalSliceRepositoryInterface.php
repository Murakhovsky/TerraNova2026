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
    public function saveExecutionPlan(string $organizationId,string $planId,string $opportunityId,array $payload):void;

    /** @param array<string,mixed> $payload */
    public function savePaperOrder(string $organizationId,string $orderId,string $executionId,string $legId,string $state,string $idempotencyKey,array $payload):void;

    /** @param array<string,mixed> $payload */
    public function savePaperFill(string $organizationId,string $fillId,string $orderId,string $executionId,string $idempotencyKey,array $payload):void;

    /** @param array<string,mixed> $payload */
    public function savePosition(string $organizationId,string $positionId,string $portfolioId,string $strategyId,string $instrumentId,string $venueId,string $status,array $payload):void;

    /** @return list<array<string,mixed>> */
    public function listPositions(string $organizationId,int $limit=500):array;

    /** @return array<string,mixed>|null */
    public function getExecutionForOpportunity(string $organizationId,string $opportunityId):?array;

    /** @return array<string,mixed>|null */
    public function getExecution(string $organizationId,string $executionId):?array;

    /** @return list<array<string,mixed>> */
    public function listPaperOrdersForExecution(string $organizationId,string $executionId):array;

    /** @return list<array<string,mixed>> */
    public function listPaperFillsForExecution(string $organizationId,string $executionId):array;

    public function ledgerTransactionExists(string $organizationId,string $idempotencyKey):bool;

    /** @param array<string,mixed> $payload */
    public function saveLedgerTransaction(string $organizationId,string $transactionId,string $idempotencyKey,array $payload):void;

    /** @return array<string,mixed> */
    public function initializePaperPortfolio(string $organizationId,string $currency,string $initialCapital):array;

    /** @return array<string,mixed>|null */
    public function paperPortfolio(string $organizationId):?array;

    public function reserveCapital(
        string $organizationId,string $reservationId,string $opportunityId,string $amount,string $expiresAt
    ):bool;

    public function releaseReservation(string $organizationId,string $reservationId):void;

    public function completeReservation(string $organizationId,string $reservationId,string $realizedPnl):void;

    public function setPaperBalance(
        string $organizationId,string $venueId,string $assetKey,string $amount
    ):void;

    /** @return list<array<string,mixed>> */
    public function listPaperBalances(string $organizationId):array;

    public function reservePaperBalance(
        string $organizationId,string $reservationId,string $opportunityId,
        string $venueId,string $assetKey,string $amount,string $expiresAt
    ):bool;

    public function releasePaperBalanceReservation(string $organizationId,string $reservationId):void;

    public function consumePaperBalanceReservation(string $organizationId,string $reservationId):void;

    public function creditPaperBalance(
        string $organizationId,string $venueId,string $assetKey,string $amount
    ):void;

    /** @return list<array<string,mixed>> */
    public function listOpportunities(string $organizationId,int $limit=200):array;

    /** @param array<string,mixed> $payload */
    public function saveHypothesisObservation(
        string $organizationId,
        string $observationId,
        string $hypothesis,
        string $stage,
        string $observedAt,
        string $fingerprint,
        array $payload
    ):void;

    /** @return list<array<string,mixed>> */
    public function listHypothesisObservations(string $organizationId,?string $hypothesis=null,int $limit=10000):array;

    /** @return array<string,mixed> */
    public function dashboard(string $organizationId):array;
}
