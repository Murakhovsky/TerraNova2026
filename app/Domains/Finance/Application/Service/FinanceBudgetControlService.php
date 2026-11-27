<?php
declare(strict_types=1);
namespace Domains\Finance\Application\Service;

use DomainException;
use Domains\Finance\Application\Contract\FinanceBudgetControlInterface;
use Domains\Finance\Application\Contract\FinanceBudgetRepositoryInterface;
use Domains\Finance\Domain\BudgetControlPolicy;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

/** Finance-owned business entrypoint. No payment or provider write authority. */
final readonly class FinanceBudgetControlService implements FinanceBudgetControlInterface
{
    public function __construct(private FinanceBudgetRepositoryInterface $repository,private BudgetControlPolicy $policy) {}

    public function createDraft(TenantContext $actor,array $input,string $key):array
    {
        $this->manager($actor);
        return $this->repository->createDraft($actor->organizationId()->value(),$actor->userId()->value(),
            $this->policy->draft($input),BudgetControlPolicy::key($key));
    }
    public function envelope(TenantContext $actor,string $envelopeId):?array
    {
        $this->manager($actor);
        return $this->repository->envelope($actor->organizationId()->value(),BudgetControlPolicy::id($envelopeId));
    }
    public function list(TenantContext $actor,string $query,int $offset,int $limit):array
    {
        $this->manager($actor);
        if(mb_strlen($query)>120||$offset<0||$offset>200000||$limit<1||$limit>100)throw new DomainException('Invalid budget page.');
        $rows=$this->repository->workspacePage($actor->organizationId()->value(),trim($query),$offset,$limit+1);
        return ['items'=>array_slice($rows,0,$limit),'has_more'=>count($rows)>$limit];
    }
    public function reservations(TenantContext $actor,string $envelopeId):array
    {
        $this->manager($actor);
        return $this->repository->reservations($actor->organizationId()->value(),BudgetControlPolicy::id($envelopeId));
    }

    private function manager(TenantContext $actor):void
    {
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)){
            throw new DomainException('Finance budget tenant manager required.');
        }
    }
}

