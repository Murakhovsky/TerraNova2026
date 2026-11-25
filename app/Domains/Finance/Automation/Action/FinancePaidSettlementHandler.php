<?php
declare(strict_types=1);
namespace Domains\Finance\Automation\Action;
use Domains\Finance\Application\Service\ApprovedFinancePaidSettlementService;
use Kernel\Action\Action;
use Kernel\Action\Contract\IdempotentExternalActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\ExternalActionIdempotency;
use Kernel\Identity\Contract\IdentityResolverInterface;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Service\TenantContextFactory;
use DomainException;
use Throwable;
final readonly class FinancePaidSettlementHandler implements IdempotentExternalActionHandlerInterface
{
    public function __construct(private ApprovedFinancePaidSettlementService $service,private IdentityResolverInterface $identities,private TenantContextFactory $tenants){}
    public function supports(string $type):bool{return $type===ApprovedFinancePaidSettlementService::TYPE;}
    public function idempotencyKey(Action $action):string{return ExternalActionIdempotency::resolve($action);}
    public function execute(Action $action):ExecutionResult
    {
        try{
            if(!$this->supports($action->type)||!ctype_digit($action->sourceId)||(int)$action->sourceId<1)throw new DomainException('Invalid settlement actor.');
            $identity=$this->identities->resolve(UserId::fromString($action->sourceId),OrganizationId::fromString($action->organizationId));
            if($identity===null||$identity->userId()->value()!==$action->sourceId||$identity->organizationId()->value()!==$action->organizationId)
                throw new DomainException('Current tenant membership required.');
            return ExecutionResult::success($this->service->execute($action,$this->tenants->fromIdentity($identity)));
        }catch(Throwable){return ExecutionResult::failure('Finance settlement blocked or uncertain; inspect immutable receipt, never retry blindly.');}
    }
}
