<?php
declare(strict_types=1);
namespace App\Infrastructure\Capability;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Infrastructure\Persistence\MySqlMarketingStore;
use DomainException;
use Kernel\Tenant\Model\TenantContext;

final readonly class MarketingCampaignCreateAdapter implements CapabilityInvocationAdapterInterface {
 public function __construct(private MySqlMarketingStore $store){}
 public function binding():string{return 'command:marketing.campaign.create';}
 public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed {
  if(!$actor->isManager())throw new DomainException('Tenant manager required.');
  return $this->store->createDraft($actor->organizationId()->value(),(int)$actor->userId()->value(),$input,$key);
 }
}
