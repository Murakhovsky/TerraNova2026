<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Application\Contract;
interface CapitalRiskRepositoryInterface
{
 public function saveExposureSnapshot(string $organizationId,array $record):void;
 public function latestExposureSnapshot(string $organizationId,string $portfolioId):?array;
 public function saveRiskSnapshot(string $organizationId,array $record):void;
 public function latestRiskSnapshot(string $organizationId,string $portfolioId):?array;
 public function saveRiskEnvelope(string $organizationId,array $record):void;
 public function latestRiskEnvelope(string $organizationId,string $portfolioId):?array;
 public function saveAllocationPolicy(string $organizationId,array $record):void;
 public function latestAllocationPolicy(string $organizationId,string $portfolioId,string $mode):?array;
 public function saveAllocationPlan(string $organizationId,array $record):void;
 public function latestAllocationPlan(string $organizationId,string $portfolioId):?array;
 public function approveAllocation(string $organizationId,string $planId,string $actorId,string $approvedAt):bool;
 public function saveStrategyAllocation(string $organizationId,array $record):void;
 public function listStrategyAllocations(string $organizationId,string $portfolioId):array;
 public function saveRebalancePlan(string $organizationId,array $record):void;
 public function latestRebalancePlan(string $organizationId,string $portfolioId):?array;
 public function saveCorrelationSnapshot(string $organizationId,array $record):void;
 public function latestCorrelationSnapshot(string $organizationId,string $portfolioId):?array;
 public function saveStressResult(string $organizationId,array $record):void;
 public function listStressResults(string $organizationId,string $portfolioId,int $limit=50):array;
}
