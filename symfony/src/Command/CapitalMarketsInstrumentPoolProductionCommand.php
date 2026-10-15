<?php
declare(strict_types=1);

namespace App\Command;

use DomainException;
use Domains\CapitalMarkets\Application\Service\InstrumentPoolExternalEvidence;
use Domains\CapitalMarkets\Application\Service\InstrumentPoolProductionEvidenceReader;
use Domains\CapitalMarkets\Application\Service\MarketDataAdministrationService;
use Domains\CapitalMarkets\Application\Contract\MarketSourceRepositoryInterface;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use PDO;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Opt-in, tenant-specific deployment. Enabling a source does NOT start a
 * WebSocket worker or grant credentials; separately supervised workers required.
 */
#[AsCommand(name:'cos:capital-markets:pool:production',
    description:'Guarded pool canary, LIVE MySQL soak, promotion, independent evidence and rollback.')]
final class CapitalMarketsInstrumentPoolProductionCommand extends Command
{
    public function __construct(
        private readonly PDO $connection,
        private readonly TransactionManagerInterface $transactions,
        private readonly InstrumentPoolExternalEvidence $evidence,
        private readonly InstrumentPoolProductionEvidenceReader $reader,
        private readonly MarketSourceRepositoryInterface $sources,
        private readonly MarketDataAdministrationService $administration,
    ){parent::__construct();}

    protected function configure():void
    {
        $this->addArgument('action',\Symfony\Component\Console\Input\InputArgument::REQUIRED,
                'status|attest|review|revoke|assign-shard|canary|soak|accept|promote|rollback|watchdog')
            ->addOption('organization',null,InputOption::VALUE_REQUIRED,'Tenant (required)',null)
            ->addOption('actor',null,InputOption::VALUE_REQUIRED,'Authorized operator id','0')
            ->addOption('sources',null,InputOption::VALUE_REQUIRED,'Comma-separated approved source IDs')
            ->addOption('source',null,InputOption::VALUE_REQUIRED,'Existing canonical market source ID')
            ->addOption('instrument',null,InputOption::VALUE_REQUIRED,'Existing canonical ACTIVE instrument ID')
            ->addOption('kind',null,InputOption::VALUE_REQUIRED,'BBO|L2')
            ->addOption('shard',null,InputOption::VALUE_REQUIRED,'Exact supervised worker shard key')

            ->addOption('subject',null,InputOption::VALUE_REQUIRED,'Source or canonical instrument ID')
            ->addOption('document-url',null,InputOption::VALUE_REQUIRED,'Permanent HTTPS evidence URL')
            ->addOption('sha256',null,InputOption::VALUE_REQUIRED,'SHA256 of reviewed document')
            ->addOption('expires',null,InputOption::VALUE_REQUIRED,'YYYY-MM-DD HH:MM:SS UTC')
            ->addOption('evidence-id',null,InputOption::VALUE_REQUIRED,'Attestation row id')
            ->addOption('decision',null,InputOption::VALUE_REQUIRED,'approve|reject')
            ->addOption('reason',null,InputOption::VALUE_REQUIRED,'Reason for independent revocation')
            ->addOption('confirm',null,InputOption::VALUE_REQUIRED,'Explicit action confirmation');
    }

    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        $action=strtolower(trim((string)$input->getArgument('action')));
        $org=trim((string)$input->getOption('organization'));
        try{
            if($org===''||strlen($org)>190)throw new DomainException('Explicit --organization is required.');
            if($action==='status'){
                $actor=(int)$input->getOption('actor');
                $this->evidence->assertAuthorized($org,$actor,'capital_markets.market_data.view');
                $row=$this->control($org);
                $result=['control'=>$row,'acceptance'=>null];
                if(is_array($row)&&in_array($row['phase'],['SOAK','ACCEPTED','LIVE'],true)){
                    $result['acceptance']=$this->reader->report($org,$row['soak_started_at'],$this->decodeIds($row['source_ids_json']));
                }
                return $this->out($output,$result,Command::SUCCESS);
            }
            $actor=(int)$input->getOption('actor');
            if($actor<1||!ctype_digit((string)$input->getOption('actor'))){
                throw new DomainException('Positive --actor required.');
            }
            if($action==='attest'){
                $id=$this->evidence->propose($org,$actor,
                    (string)$input->getOption('kind'),(string)$input->getOption('subject'),
                    (string)$input->getOption('document-url'),(string)$input->getOption('sha256'),
                    (string)$input->getOption('expires'));
                return $this->out($output,['status'=>'PENDING','evidence_id'=>$id],Command::SUCCESS);
            }
            if($action==='review'){
                $decision=(string)$input->getOption('decision');
                if(!in_array($decision,['approve','reject'],true))throw new DomainException('Explicit review decision required.');
                $this->evidence->review($org,$actor,(int)$input->getOption('evidence-id'),$decision==='approve');
                return $this->out($output,['status'=>strtoupper($decision)],Command::SUCCESS);
            }
            if($action==='revoke'){
                $this->confirm($input,'REVOKE_POOL_EVIDENCE');
                $this->evidence->revoke($org,$actor,
                    (int)$input->getOption('evidence-id'),
                    (string)$input->getOption('reason'));
                return $this->out($output,['status'=>'REVOKED'],Command::SUCCESS);
            }
            if($action==='assign-shard'){
                $this->confirm($input,'ASSIGN_POOL_SHARD');
                $assignment=$this->assignShard($org,$actor,
                    (string)$input->getOption('source'),(string)$input->getOption('instrument'),
                    (string)$input->getOption('kind'),(string)$input->getOption('shard'));
                return $this->out($output,$assignment,Command::SUCCESS);
            }
            if(!in_array($action,['canary','soak','accept','promote','rollback','watchdog'],true)){
                throw new DomainException('Unknown production action.');
            }
            $this->evidence->assertAuthorized($org,$actor,'capital_markets.market_data.manage');
            if(!in_array($action,['rollback','watchdog'],true)
                &&getenv('COS_CM_POOL_PRODUCTION_ARMED')!=='1'){
                throw new DomainException('COS_CM_POOL_PRODUCTION_ARMED=1 required on target runtime.');
            }
            if($action!=='watchdog')$this->confirm($input,strtoupper($action).'_INSTRUMENT_POOL');
            $result=match($action){
                'canary'=>$this->canary($org,$actor,$this->sourceIds($input)),
                'soak'=>$this->soak($org,$actor,$this->sourceIds($input)),
                'accept'=>$this->accept($org,$actor),
                'promote'=>$this->promote($org,$actor),
                'rollback'=>$this->rollback($org,$actor),
                'watchdog'=>$this->watchdog($org,$actor),
            };
            return $this->out($output,$result,Command::SUCCESS);
        }catch(Throwable $e){
            return $this->out($output,['ok'=>false,'action'=>$action,
                'error_type'=>get_class($e),'error'=>$e instanceof DomainException
                    ?$e->getMessage():'Production action failed without credential disclosure.'],Command::FAILURE);
        }
    }

    /** @return array<string,string> */
    private function assignShard(string $org,int $actor,string $source,string $instrument,
        string $kind,string $shard):array
    {
        $this->evidence->assertAuthorized($org,$actor,'capital_markets.market_data.manage');
        if(getenv('COS_CM_POOL_PRODUCTION_ARMED')!=='1'){
            throw new DomainException('Explicit production arming required for shard assignment.');
        }
        if(!in_array($kind,['BBO','L2'],true)
            ||$shard===''||strlen($shard)>190
            ||preg_match('/^[a-zA-Z0-9:_-]+$/D',$shard)!==1
            ||$source===''||$instrument===''||strlen($instrument)>190){
            throw new DomainException('Exact source, canonical instrument, BBO/L2 and shard required.');
        }
        $sourceId=MarketSourceId::fromString($source);
        return $this->transactions->transactional(function()use(
            $org,$actor,$source,$instrument,$kind,$shard,$sourceId):array {
            $control=$this->lockedControl($org);
            if(!in_array($control['phase']??null,['OFF','ROLLED_BACK'],true)){
                throw new DomainException('Shard configuration is immutable while a rollout is running.');
            }
            $src=$this->sources->get($org,$sourceId);
            if($src===null||$src->enabled){
                throw new DomainException('Configure only an existing disabled source.');
            }
            $exists=$this->connection->prepare(
                "SELECT status FROM tn_capital_market_instruments
                 WHERE organization_id=:org AND instrument_id=:instrument");
            $exists->execute(['org'=>$org,'instrument'=>$instrument]);
            if($exists->fetchColumn()!=='ACTIVE'){
                throw new DomainException('Only ACTIVE canonical instruments can be assigned.');
            }
            $save=$this->connection->prepare(
                "INSERT INTO tn_capital_market_pool_stream_assignments
                 (organization_id,source_id,instrument_id,kind,shard_key,assigned_by)
                 VALUES (:org,:source,:instrument,:kind,:shard,:actor)
                 ON DUPLICATE KEY UPDATE shard_key=VALUES(shard_key),
                    assigned_by=VALUES(assigned_by),updated_at=UTC_TIMESTAMP(6)");
            $save->execute(['org'=>$org,'source'=>$source,'instrument'=>$instrument,
                'kind'=>$kind,'shard'=>$shard,'actor'=>$actor]);
            return ['status'=>'REGISTERED','source_id'=>$source,
                'instrument_id'=>$instrument,'kind'=>$kind,'shard_key'=>$shard];
        });
    }

    /** @return list<string> */
    private function sourceIds(InputInterface $input):array
    {
        $raw=(string)$input->getOption('sources');
        $ids=array_map('trim',explode(',',$raw));
        if($raw===''||count($ids)>64||count(array_unique($ids))!==count($ids)){
            throw new DomainException('1..64 unique source IDs required.');
        }
        foreach($ids as $id){
            if($id===''||strlen($id)>190||preg_match('/^[a-zA-Z0-9:_-]+$/D',$id)!==1){
                throw new DomainException('Invalid source ID.');
            }
        }
        return $ids;
    }

    private function assertSourceAttested(string $org,array $ids):void
    {
        $approved=$this->evidence->approved($org);
        foreach($ids as $id){
            $src=$this->sources->get($org,MarketSourceId::fromString($id));
            if($src===null||$src->licenseProfile==='UNSPECIFIED'
                ||!isset($approved['SOURCE_ENTITLEMENT|'.$id])
                ||($src->adapterType==='massive.stocks.v2'
                    &&$src->credentialsReference===null)){
                throw new DomainException('Source '.$id.' lacks reviewed entitlement, license profile or vault credential.');
            }
        }
    }

    private function canary(string $org,int $actor,array $ids):array
    {
        if(count($ids)!==1)throw new DomainException('Exactly one source per initial canary.');
        $this->assertSourceAttested($org,$ids);
        $source=$this->sources->get($org,MarketSourceId::fromString($ids[0]));
        if($source===null||$source->enabled)throw new DomainException('Canary must start from an OFF source.');
        $this->transactions->transactional(function() use ($org,$actor,$ids):void {
            $old=$this->lockedControl($org);
            if($old!==null&&!in_array($old['phase'],['OFF','ROLLED_BACK'],true)){
                throw new DomainException('Another rollout already owns this tenant.');
            }
            $current=$this->sources->get($org,MarketSourceId::fromString($ids[0]));
            if($current===null||$current->enabled){
                throw new DomainException('Canary source changed during lock acquisition.');
            }
            $this->assertSourceAttested($org,$ids);
            $this->saveControl($org,'CANARY',$ids,$ids,$actor,null);
            $this->administration->setSourceEnabled($org,$actor,$this->correlation(),$ids[0],true);
        });
        return ['phase'=>'CANARY','sources'=>$ids,'live_confirmed'=>false,
            'next'=>'Start independently supervised live feed, then soak after fresh TRUSTED samples.'];
    }

    private function soak(string $org,int $actor,array $ids):array
    {
        $old=$this->control($org);
        if(!is_array($old)||$old['phase']!=='CANARY')throw new DomainException('CANARY required before soak.');
        $already=$this->decodeIds($old['source_ids_json']);
        foreach($already as $s)if(!in_array($s,$ids,true))throw new DomainException('Cannot drop canary source in soak.');
        if(!$this->reader->canaryHealthy($org,$already)){
            throw new DomainException('No recent verified live canary observations.');
        }
        $this->assertSourceAttested($org,$ids);
        $new=array_values(array_diff($ids,$already));
        foreach($new as $id){
            $src=$this->sources->get($org,MarketSourceId::fromString($id));
            if($src===null||$src->enabled){
                throw new DomainException('New soak source must start disabled: '.$id);
            }
        }
        $disabled=array_values(array_unique(array_merge(
            $this->decodeIds($old['initially_disabled_json']),$new)));
        $this->transactions->transactional(function() use ($org,$actor,$ids,$new,$disabled):void {
            $locked=$this->lockedControl($org);
            if(!is_array($locked)||$locked['phase']!=='CANARY')throw new DomainException('Concurrent rollout modification.');
            foreach($new as $id){
                $current=$this->sources->get($org,MarketSourceId::fromString($id));
                if($current===null||$current->enabled){
                    throw new DomainException('Soak source changed during lock acquisition.');
                }
                $this->administration->setSourceEnabled($org,$actor,$this->correlation(),$id,true);
            }
            $this->saveControl($org,'SOAK',$ids,$disabled,(int)$locked['operator_id'],
                gmdate('Y-m-d H:i:s'));
        });
        return ['phase'=>'SOAK','sources'=>$ids,'window'=>'24 hours of real MySQL evidence',
            'external_workers_required'=>true,'accepted'=>false];
    }

    private function accept(string $org,int $actor):array
    {
        $old=$this->control($org);
        if(!is_array($old)||$old['phase']!=='SOAK'||(int)$old['operator_id']===$actor){
            throw new DomainException('Independent operator and SOAK phase required.');
        }
        $report=$this->reader->report($org,$old['soak_started_at'],$this->decodeIds($old['source_ids_json']));
        if(!$report['accepted'])throw new DomainException(
            '24h LIVE 250 BBO + 50 L2 quality/entitlement gates are not green.'
        );
        $checkpoints=$this->reader->soakCheckpoints($org,$old['soak_started_at']);
        if(!$checkpoints['accepted']){
            throw new DomainException('Durable 5-minute soak watchdog observations are insufficient.');
        }
        $this->transactions->transactional(function() use ($org,$actor,$old,$report):void {
            $locked=$this->lockedControl($org);
            if(!is_array($locked)||$locked['phase']!=='SOAK'||$locked['soak_started_at']!==$old['soak_started_at']){
                throw new DomainException('Soak changed during acceptance.');
            }
            $q=$this->connection->prepare(
                "UPDATE tn_capital_market_pool_production_control SET phase='ACCEPTED',
                 accepted_at=UTC_TIMESTAMP(6),acceptance_digest=:digest,
                 last_reviewed_by=:actor WHERE organization_id=:org");
            $q->execute(['digest'=>$report['digest'],'actor'=>$actor,'org'=>$org]);
        });
        return ['phase'=>'ACCEPTED','acceptance'=>$report,
            'checkpoints'=>$checkpoints,'production_running'=>false];
    }

    private function promote(string $org,int $actor):array
    {
        $old=$this->control($org);
        if(!is_array($old)||$old['phase']!=='ACCEPTED'
            ||(int)$old['operator_id']===$actor
            ||strtotime((string)$old['accepted_at'].' UTC')<time()-600){
            throw new DomainException('Fresh independent acceptance required within 10 minutes.');
        }
        $this->assertSourceAttested($org,$this->decodeIds($old['source_ids_json']));
        $report=$this->reader->report($org,$old['soak_started_at'],$this->decodeIds($old['source_ids_json']));
        if(!$report['accepted']
            ||!$this->reader->soakCheckpoints($org,$old['soak_started_at'])['accepted']){
            throw new DomainException('Live evidence or durable soak checkpoints degraded.');
        }
        $this->transactions->transactional(function() use ($org,$actor):void {
            $locked=$this->lockedControl($org);
            if(!is_array($locked)||$locked['phase']!=='ACCEPTED'){
                throw new DomainException('Concurrent release state change.');
            }
            $q=$this->connection->prepare(
                "UPDATE tn_capital_market_pool_production_control SET phase='LIVE',
                 last_reviewed_by=:actor WHERE organization_id=:org AND phase='ACCEPTED'");
            $q->execute(['actor'=>$actor,'org'=>$org]);
            if($q->rowCount()!==1)throw new DomainException('Release already changed.');
        });
        return ['phase'=>'LIVE','gate'=>$report,'requires_continuous_watchdog'=>true,
            'trading_enabled'=>false];
    }

    private function rollback(string $org,int $actor):array
    {
        $disabled=$this->transactions->transactional(function() use ($org,$actor):array {
            $old=$this->lockedControl($org);
            if(!is_array($old)||in_array($old['phase'],['OFF','ROLLED_BACK'],true)){
                throw new DomainException('No active pool rollout to roll back.');
            }
            $disabled=$this->decodeIds($old['initially_disabled_json']);
            // Fenced shutdown. No prior user-enabled sources are touched.
            foreach($disabled as $id){
                $q=$this->connection->prepare(
                    "UPDATE tn_capital_market_stream_worker_leases
                     SET owner_id='',fencing_token=fencing_token+1,
                         expires_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6)
                     WHERE organization_id=:org AND source_id=:source");
                $q->execute(['org'=>$org,'source'=>$id]);
                $q=$this->connection->prepare(
                    "UPDATE tn_capital_market_subscriptions SET status='DISABLED'
                     WHERE organization_id=:org AND source_id=:source
                       AND status<>'DISABLED'");
                $q->execute(['org'=>$org,'source'=>$id]);
                foreach(['tn_capital_market_states','tn_capital_market_reference_states'] as $table){
                    $q=$this->connection->prepare(
                        "UPDATE $table SET trust_status='UNTRUSTED',quality_score=0,
                         state_json=JSON_SET(state_json,'$.trust_status','UNTRUSTED',
                           '$.quality_status','UNTRUSTED'),
                         updated_at=UTC_TIMESTAMP(6)
                         WHERE organization_id=:org AND source_id=:source");
                    $q->execute(['org'=>$org,'source'=>$id]);
                }
                $this->administration->setSourceEnabled($org,$actor,$this->correlation(),$id,false);
            }
            $q=$this->connection->prepare(
                "UPDATE tn_capital_market_pool_production_control
                 SET phase='ROLLED_BACK',accepted_at=NULL,acceptance_digest=NULL,
                     last_reviewed_by=:actor WHERE organization_id=:org");
            $q->execute(['org'=>$org,'actor'=>$actor]);
            return $disabled;
        });
        return ['phase'=>'ROLLED_BACK','disabled_sources'=>$disabled,'schema_rollback'=>false];
    }

    private function watchdog(string $org,int $actor):array
    {
        $control=$this->control($org);
        if(!is_array($control)){
            return ['phase'=>'OFF','monitoring'=>'NOT_LIVE'];
        }
        if($control['phase']==='CANARY'){
            return ['phase'=>'CANARY','monitoring'=>'PILOT',
                'healthy'=>$this->reader->canaryHealthy($org,
                    $this->decodeIds($control['source_ids_json']))];
        }
        if($control['phase']==='SOAK'){
            return ['phase'=>'SOAK','monitoring'=>'RECORDING_LIVE_OBSERVATIONS',
                'checkpoint'=>$this->reader->recordSoakCheckpoint(
                    $org,$control['soak_started_at'],
                    $this->decodeIds($control['source_ids_json']))];
        }
        if($control['phase']!=='LIVE'){
            return ['phase'=>$control['phase'],'monitoring'=>'NOT_LIVE'];
        }
        try{
            $report=$this->reader->report($org,$control['soak_started_at'],
                $this->decodeIds($control['source_ids_json']));
        }catch(Throwable $error){
            $reverted=$this->rollback($org,$actor);
            return ['monitoring'=>'EVIDENCE_ERROR_ROLLBACK_APPLIED',
                'error_type'=>get_class($error),'rollback'=>$reverted];
        }
        if(!$report['accepted']){
            $reverted=$this->rollback($org,$actor);
            return ['monitoring'=>'FAILED_ROLLBACK_APPLIED','acceptance'=>$report,'rollback'=>$reverted];
        }
        return ['monitoring'=>'HEALTHY','acceptance'=>$report];
    }

    private function control(string $org):?array
    {
        $q=$this->connection->prepare(
            'SELECT * FROM tn_capital_market_pool_production_control WHERE organization_id=:org');
        $q->execute(['org'=>$org]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    private function lockedControl(string $org):?array
    {
        // Serialize even FIRST activation: SELECT FOR UPDATE on a missing row
        // does not reliably lock a tenant-specific non-existing rollout.
        $initialize=$this->connection->prepare(
            "INSERT IGNORE INTO tn_capital_market_pool_production_control
             (organization_id,phase,source_ids_json,initially_disabled_json,operator_id)
             VALUES (:org,'OFF','[]','[]',0)");
        $initialize->execute(['org'=>$org]);
        $q=$this->connection->prepare(
            'SELECT * FROM tn_capital_market_pool_production_control
             WHERE organization_id=:org FOR UPDATE');
        $q->execute(['org'=>$org]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    private function saveControl(string $org,string $phase,array $sources,
        array $disabled,int $operator,?string $soakStart):void
    {
        $q=$this->connection->prepare(
            "INSERT INTO tn_capital_market_pool_production_control
             (organization_id,phase,source_ids_json,initially_disabled_json,
              started_at,soak_started_at,operator_id,accepted_at,acceptance_digest)
             VALUES (:org,:phase,:sources,:disabled,UTC_TIMESTAMP(6),:soak,:operator,NULL,NULL)
             ON DUPLICATE KEY UPDATE phase=VALUES(phase),
              source_ids_json=VALUES(source_ids_json),
              initially_disabled_json=VALUES(initially_disabled_json),
              soak_started_at=VALUES(soak_started_at),operator_id=VALUES(operator_id),
              accepted_at=NULL,acceptance_digest=NULL");
        $q->execute(['org'=>$org,'phase'=>$phase,
            'sources'=>json_encode($sources,JSON_THROW_ON_ERROR),
            'disabled'=>json_encode($disabled,JSON_THROW_ON_ERROR),
            'soak'=>$soakStart,'operator'=>$operator]);
    }

    private function decodeIds(string $encoded):array
    {
        $array=json_decode($encoded,true,flags:JSON_THROW_ON_ERROR);
        if(!is_array($array)||count($array)>64)throw new DomainException('Corrupted rollout source scope.');
        foreach($array as $id)if(!is_string($id)||$id===''||strlen($id)>190){
            throw new DomainException('Invalid rollout source reference.');
        }
        return array_values($array);
    }

    private function correlation():string
    {
        return 'cm-prod-'.bin2hex(random_bytes(16));
    }

    private function confirm(InputInterface $input,string $expected):void
    {
        if((string)$input->getOption('confirm')!==$expected){
            throw new DomainException('Lifecycle action requires --confirm='.$expected);
        }
    }

    private function out(OutputInterface $out,array $result,int $code):int
    {
        $out->writeln(json_encode($result,
            JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        return $code;
    }
}
