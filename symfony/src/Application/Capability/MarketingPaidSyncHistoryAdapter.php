<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingPaidReadService;
use Kernel\Tenant\Model\TenantContext;

/** Sanitized Meta read sync status; no credential or provider error exposure. */
final readonly class MarketingPaidSyncHistoryAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingPaidReadService $paid){}
    public function binding():string{return 'query:marketing.paid.sync.history';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        return ['runs'=>$this->paid->syncHistory($actor,(string)$input['account_id'],(int)($input['limit']??20))];
    }
}
