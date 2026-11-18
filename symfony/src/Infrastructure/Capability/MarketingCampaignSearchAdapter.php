<?php
declare(strict_types=1);
namespace App\Infrastructure\Capability;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Infrastructure\Persistence\MySqlMarketingStore;
use DomainException;
use Kernel\Tenant\Model\TenantContext;

final readonly class MarketingCampaignSearchAdapter implements CapabilityInvocationAdapterInterface {
 public function __construct(private MySqlMarketingStore $store){}
 public function binding():string{return 'query:marketing.campaign.search';}
 public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed {
  if(!$actor->isManager())throw new DomainException('Tenant manager required.');
  foreach(['organization_id','tenant_id','actor_id'] as $field)
   if(array_key_exists($field,$input))throw new DomainException('Untrusted tenant identity.');
  return ['items'=>$this->store->campaigns($actor->organizationId()->value(),(string)($input['q']??''),(int)($input['limit']??30))];
 }
}
