<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingPlanningService;
use Kernel\Tenant\Model\TenantContext;

final readonly class MarketingAdsetSaveAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingPlanningService $planning){}
    public function binding():string{return 'command:marketing.adset.save';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        $id=$input['adset_id']??null;$revision=$input['expected_revision']??null;
        $campaign=$input['campaign_id'];$campaignRevision=$input['campaign_revision'];
        unset($input['adset_id'],$input['expected_revision'],$input['campaign_id'],$input['campaign_revision']);
        return $this->planning->saveAdset($actor,$campaign,$campaignRevision,$id,$revision,$input,$key);
    }
}
