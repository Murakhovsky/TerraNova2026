<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingPlanningService;
use Kernel\Tenant\Model\TenantContext;

final readonly class MarketingAudienceSaveAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingPlanningService $planning){}
    public function binding():string{return 'command:marketing.audience.save';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        $id=$input['audience_id']??null;$revision=$input['expected_revision']??null;
        unset($input['audience_id'],$input['expected_revision']);
        return $this->planning->saveAudience($actor,$id,$revision,$input,$key);
    }
}
