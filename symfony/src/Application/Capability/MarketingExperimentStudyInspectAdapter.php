<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingExperimentService;
use Kernel\Tenant\Model\TenantContext;

final readonly class MarketingExperimentStudyInspectAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingExperimentService $experiments){}
    public function binding():string{return 'query:marketing.experiment.study.inspect';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        return $this->experiments->observeStudy($actor,$input['experiment_id'],$input['study_id'],$input['control_snapshot_ref'],$input['candidate_snapshot_ref']);
    }
}
