<?php
declare(strict_types=1);
namespace App\Infrastructure\Capability;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Infrastructure\Persistence\MySqlMarketingStore;
use DomainException;
use Kernel\Tenant\Model\TenantContext;

final readonly class MarketingPaidAccountsAdapter implements CapabilityInvocationAdapterInterface {
 public function __construct(private MySqlMarketingStore $store){}
 public function binding():string{return 'query:marketing.paid.account.list';}
 public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed {
  if(!$actor->isManager()||$input!==[])throw new DomainException('Tenant manager and empty query required.');
  return ['items'=>$this->store->accounts($actor->organizationId()->value())];
 }
}
