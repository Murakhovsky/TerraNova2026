<?php
declare(strict_types=1);
namespace App\Application\CapitalMarkets\Handler;
use App\Application\CapitalMarkets\Command\RunInstrumentPoolSnapshotScans;
use Domains\CapitalMarkets\Application\Service\InstrumentPoolCatalogAudit;
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
        private MysqlInstrumentPoolSnapshotRepository $snapshots,
        private ActiveModuleResolver $modules,
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
                if(!$this->snapshots->reserveScan($org,$provider,$venue,90)){
                    $result['skipped']++;continue;
                }
                $data=$this->audit->inspect($org,$provider,$venue,500);
                $this->snapshots->record($org,$this->actorId,$data);
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
