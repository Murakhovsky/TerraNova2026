<?php
declare(strict_types=1);
namespace Domains\Finance\Application\Service;

use DomainException;
use Domains\Finance\Application\Contract\FinanceSpendObservationRepositoryInterface;
use Domains\Finance\Domain\FinanceSpendObservationPolicy;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

/**
 * F1 evidence-only internal boundary: no public record endpoint until Marketing
 * readback and trusted cross-domain origin can be verified after PR acceptance.
 */
final readonly class FinanceSpendObservationService
{
    public function __construct(
        private FinanceSpendObservationRepositoryInterface $repository,
        private FinanceSpendObservationPolicy $policy
    ){}

    /** @param array<string,mixed> $marketingSnapshot @return array<string,mixed> */
    public function acceptUnverifiedMarketingSnapshot(string $organizationId,array $marketingSnapshot,string $correlationId):array
    {
        if($organizationId===''||strlen($organizationId)>190
            ||!preg_match('/^[A-Za-z0-9_.:-]{8,190}$/D',$correlationId)){
            throw new DomainException('Trusted tenant and stable correlation are required for an evidence import.');
        }
        // Intentionally NOT exposed as a Capability writer or HTTP endpoint.
        return $this->repository->record($organizationId,$this->policy->validate($marketingSnapshot),$correlationId);
    }

    /** @return array<string,mixed> */
    public function status(TenantContext $actor,string $account,string $from,string $to,string $currency,string $timezone,string $attribution):array
    {
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)){
            throw new DomainException('Finance tenant manager required.');
        }
        return $this->repository->reconciliationStatus($actor->organizationId()->value(),
            $this->policy->scope($account,$from,$to,$currency,$timezone,$attribution));
    }
}

