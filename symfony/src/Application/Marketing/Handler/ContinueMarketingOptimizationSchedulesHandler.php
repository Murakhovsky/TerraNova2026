<?php
declare(strict_types=1);
namespace App\Application\Marketing\Handler;
use App\Application\Marketing\Command\ContinueMarketingOptimizationSchedules;
use App\Persistence\Marketing\MarketingOptimizationScheduleService;
use Domains\Marketing\Domain\MarketingOptimizationSchedulePolicy;
use Kernel\Identity\Contract\IdentityResolverInterface;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Service\TenantContextFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;
#[AsMessageHandler]
final readonly class ContinueMarketingOptimizationSchedulesHandler
{
    public function __construct(private MarketingOptimizationScheduleService $schedules,private IdentityResolverInterface $identities,
        private TenantContextFactory $tenants,private LoggerInterface $logger,private bool $enabled=false,private string $targetsJson='[]'){}
    public function __invoke(ContinueMarketingOptimizationSchedules $command):array
    {
        if(!$this->enabled)return ['status'=>'DISABLED','states'=>[],'failed'=>0];
        $states=[];$failed=0;
        foreach(MarketingOptimizationSchedulePolicy::parse($this->targetsJson) as $profile){
            try{
                $identity=$this->identities->resolve(UserId::fromString($profile['actor_id']),OrganizationId::fromString($profile['organization_id']));
                if($identity===null||$identity->userId()->value()!==$profile['actor_id']||$identity->organizationId()->value()!==$profile['organization_id'])
                    throw new \DomainException('Live schedule actor unavailable.');
                $state=$this->schedules->tick($this->tenants->fromIdentity($identity),$profile);
                $states[$state]=($states[$state]??0)+1;
            }catch(Throwable $e){
                $failed++;$this->logger->error('Marketing optimization scheduler blocked.',['profile_id'=>$profile['profile_id'],'error_type'=>get_class($e)]);
            }
        }
        return ['status'=>$failed>0?'PARTIAL_FAILURE':'COMPLETED','states'=>$states,'failed'=>$failed];
    }
}
