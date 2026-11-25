<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Contract;
use Kernel\Tenant\Model\TenantContext;
interface MarketingAttributionCandidateReadPort
{
    public function page(TenantContext $actor,string $kind,string $from,string $to,?string $after,int $limit):array;
}
