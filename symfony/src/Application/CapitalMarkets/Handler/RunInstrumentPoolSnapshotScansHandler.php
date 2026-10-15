<?php
declare(strict_types=1);
namespace App\Application\CapitalMarkets\Handler;
use App\Application\CapitalMarkets\Command\RunInstrumentPoolSnapshotScans;
use Domains\CapitalMarkets\Application\Service\InstrumentPoolCatalogAudit;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
use Domains\CapitalMarkets\Model\CapitalMarketsCapability;
use Domains\CapitalMarkets\Application\Service\InstrumentPoolApprovalService;
use Domains\CapitalMarkets\Application\Service\InstrumentPoolScheduleTargets;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\MysqlInstrumentPoolSnapshotRepository;
use Kernel\Module\ActiveModuleResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/** Bounded scheduled discovery; never creates mappings or authorizes trades. */
#[AsMessageHandler]
final readonly class RunInstrumentPoolSnapshotScansHandler
{
    public function __construct(
        private InstrumentPoolCatalogAudit $audit,
        private InstrumentPoolApprovalService $approvals,
        private MysqlInstrumentPoolSnapshotRepository $snapshots,
        private ActiveModuleResolver $modules,
        private CapitalMarketsAccessControlInterface $access,
        private LoggerInterface $logger,
        private string $targetsJson,
        private int $actorId,
    ){}

    /** @return array{status:string,targets:int,saved:int,unavailable:int,skipped:int,failed:int} */
    public function __invoke(RunInstrumentPoolSnapshotScans $command):array
    {
        if(getenv('COS_CM_INSTRUMENT_POOL_SCHEDULER_ENABLED')!=='1'){
            return ['status'=>'DISABLED','targets'=>0,'saved'=>0,'unavailable'=>0,'skipped'=>0,'failed'=>0];
        }
        if($this->actorId<1)throw new \DomainException('Instrument Pool scheduler requires an actor ID.');
        $targets=InstrumentPoolScheduleTargets::parse($this->targetsJson);
        $result=['status'=>'COMPLETED','targets'=>count($targets),'saved'=>0,'unavailable'=>0,'skipped'=>0,'failed'=>0];
        foreach($targets as $target){
            $org=$target['organization_id'];$provider=$target['provider'];$venue=$target['venue_id'];
            try{
                if(!$this->modules->isEnabled($org,'capital_markets')){
                    $result['skipped']++;continue;
                }
                if(!$this->access->hasCapability($org,$this->actorId,CapitalMarketsCapability::MarketDataManage->value)
                    && !$this->access->hasCapability($org,$this->actorId,CapitalMarketsCapability::Manage->value)){
                    throw new \DomainException('Instrument Pool scheduler actor lacks Market Data authority.');
                }
                if(!$this->snapshots->reserveScan($org,$provider,$venue,90)){
                    $result['skipped']++;continue;
                }
                $data=$this->audit->inspect($org,$provider,$venue,500);
                $snapshotId=$this->snapshots->record($org,$this->actorId,$data);
                if(getenv('COS_CM_INSTRUMENT_POOL_AUTO_PROPOSE_ENABLED')==='1' && $data['health']==='AVAILABLE'){
                    $statusProposals=$this->approvals->stageStatusCandidates(
                        $org,$this->actorId,$snapshotId,50,
                    );
                    $remaining=50-$statusProposals['created'];
                    if($remaining>0){
                        $this->approvals->stageExactIdentityLinks(
                            $org,$this->actorId,$snapshotId,$remaining,
                        );
                    }
                }
                $result['saved']++;
                if($data['health']!=='AVAILABLE')$result['unavailable']++;
            }catch(Throwable $error){
                $result['failed']++;
                $this->logger->error('Instrument Pool scheduled discovery failed.',[
                    'organization_id'=>$org,'provider'=>$provider,'venue_id'=>$venue,
                    'error_type'=>get_class($error),
                ]);
            }
        }
        if($result['failed']>0)$result['status']='PARTIAL_FAILURE';
        return $result;
    }
}
