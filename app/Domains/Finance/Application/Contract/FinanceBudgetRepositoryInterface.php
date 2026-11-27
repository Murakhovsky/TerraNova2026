<?php
declare(strict_types=1);

namespace Domains\Finance\Application\Contract;

interface FinanceBudgetRepositoryInterface
{
    /** @param array<string,mixed> $draft @return array<string,mixed> */
    public function createDraft(string $organizationId,string $actorId,array $draft,string $idempotencyKey):array;
    /** @return array<string,mixed>|null */
    public function envelope(string $organizationId,string $envelopeId):?array;
    /** @return list<array<string,mixed>> Redacted, tenant-scoped envelope page. */
    public function workspacePage(string $organizationId,string $query,int $offset,int $limit):array;
    /** @return list<array<string,mixed>> */
    public function reservations(string $organizationId,string $envelopeId):array;
    /** @return array<string,mixed> */
    public function activate(string $organizationId,string $envelopeId,string $actorId,string $actionId,string $correlationId,int $expectedRevision,string $idempotencyKey):array;
    /** @param array{source_domain:string,source_operation_key:string,amount_minor:int,reason:string,expected_revision:int} $reservation @return array<string,mixed> */
    public function reserve(string $organizationId,string $envelopeId,string $actorId,string $actionId,string $correlationId,array $reservation,string $idempotencyKey):array;
    /** @return array<string,mixed> */
    public function release(string $organizationId,string $reservationId,string $actorId,string $actionId,string $correlationId,string $reason,string $idempotencyKey):array;
    /** @return array<string,mixed> */
    public function freeze(string $organizationId,string $envelopeId,string $actorId,string $actionId,string $correlationId,string $reason,int $expectedRevision,string $idempotencyKey):array;
}

