<?php
declare(strict_types=1);
namespace App\Infrastructure\Marketing;
use Domains\Marketing\Application\Contract\MarketingAttributionCandidateReadPort;
use Domains\Sales\Application\Contract\SalesAttributionCandidateReadPort;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use DomainException;
final readonly class AttributionCandidateReadAdapter implements MarketingAttributionCandidateReadPort
{
    public function __construct(private SalesAttributionCandidateReadPort $source,private ActiveModuleResolver $modules){}
    public function page(TenantContext $actor,string $kind,string $from,string $to,?string $after,int $limit):array
    {
        $org=$actor->organizationId()->value();
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)
            ||!$this->modules->isEnabled($org,'sales'))throw new DomainException('Sales attribution candidate source unavailable.');
        return $this->source->page($org,$kind,$from,$to,$after,$limit);
    }
}
