<?php
declare(strict_types=1);
namespace Domains\Finance\Application\Service;

use Domains\Finance\Application\Contract\FinanceBudgetRepositoryInterface;
use Domains\Finance\Domain\BudgetControlPolicy;

/**
 * Internal, Action-handler-only entrypoint: an approved Action is the authority,
 * NOT a caller-supplied API boolean. No autonomous invocation adapter is exported.
 */
final readonly class ApprovedFinanceBudgetOperations
{
    public function __construct(private FinanceBudgetRepositoryInterface $repository,private BudgetControlPolicy $policy){}

    public function activate(string $org,string $envelopeId,string $actorId,string $actionId,string $trace,int $expectedRevision,string $key):array
    {
        return $this->repository->activate($org,BudgetControlPolicy::id($envelopeId),$actorId,$actionId,$trace,
            BudgetControlPolicy::revision($expectedRevision),BudgetControlPolicy::key($key));
    }

    public function reserve(string $org,string $envelopeId,string $actorId,string $actionId,string $trace,
        mixed $amount,mixed $expectedRevision,mixed $sourceDomain,mixed $externalKey,mixed $reason,string $key):array
    {
        return $this->repository->reserve($org,BudgetControlPolicy::id($envelopeId),$actorId,$actionId,$trace,
            $this->policy->reserveInput($amount,$expectedRevision,$sourceDomain,$externalKey,$reason),
            BudgetControlPolicy::key($key));
    }

    public function release(string $org,string $reservationId,string $actorId,string $actionId,string $trace,mixed $reason,string $key):array
    {
        return $this->repository->release($org,BudgetControlPolicy::id($reservationId,'finr-'),$actorId,
            $actionId,$trace,$this->policy->reason($reason),BudgetControlPolicy::key($key));
    }

    public function freeze(string $org,string $envelopeId,string $actorId,string $actionId,string $trace,mixed $reason,mixed $expectedRevision,string $key):array
    {
        return $this->repository->freeze($org,BudgetControlPolicy::id($envelopeId),$actorId,
            $actionId,$trace,$this->policy->reason($reason),BudgetControlPolicy::revision($expectedRevision),
            BudgetControlPolicy::key($key));
    }
}

