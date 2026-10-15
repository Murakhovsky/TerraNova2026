<?php
declare(strict_types=1);
namespace App\Command;

use DomainException;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
use Domains\CapitalMarkets\Application\Service\StreamingOperationsHealthEvaluator;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\{
    MysqlStreamingOperationsSnapshot,MysqlStreamingIncidentManager,StreamingIncidentDelivery
};
use Domains\CapitalMarkets\Model\CapitalMarketsCapability;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name:'cos:capital-markets:market-data:stream:incidents',
    description:'Run fail-closed source-scoped operational incident detection, alerts and independent review.')]
final class CapitalMarketsStreamingIncidentsCommand extends Command
{
    public function __construct(
        private readonly MysqlStreamingOperationsSnapshot $snapshots,
        private readonly StreamingOperationsHealthEvaluator $health,
        private readonly MysqlStreamingIncidentManager $incidents,
        private readonly StreamingIncidentDelivery $delivery,
        private readonly CapitalMarketsAccessControlInterface $access,
    ){parent::__construct();}

    protected function configure():void
    {
        $this->addArgument('action',InputArgument::REQUIRED,'scan|list|ack|resolve')
            ->addArgument('organization',InputArgument::REQUIRED,'Tenant organization ID')
            ->addArgument('actor',InputArgument::REQUIRED,'Authenticated operator ID')
            ->addOption('source',null,InputOption::VALUE_REQUIRED,'Required operational source ID')
            ->addOption('incident-id',null,InputOption::VALUE_REQUIRED,'Persistent incident ID')
            ->addOption('alert-email',null,InputOption::VALUE_REQUIRED,'Authorized operations recipient email');
    }

    protected function execute(InputInterface $in,OutputInterface $out):int
    {
        try{
            $action=(string)$in->getArgument('action');
            $org=trim((string)$in->getArgument('organization'));
            $source=trim((string)$in->getOption('source'));
            $actor=filter_var($in->getArgument('actor'),FILTER_VALIDATE_INT);
            if($org===''||strlen($org)>190||$source===''||strlen($source)>190
                ||$actor===false||$actor<1
                ||!in_array($action,['scan','list','ack','resolve'],true)){
                throw new DomainException('Explicit valid tenant, source and operator required.');
            }
            $cap=$action==='list'
                ?CapitalMarketsCapability::MarketDataView->value
                :CapitalMarketsCapability::MarketDataManage->value;
            if(!$this->access->hasCapability($org,$actor,$cap)
                &&!$this->access->hasCapability($org,$actor,CapitalMarketsCapability::Manage->value)){
                throw new DomainException('Unauthorized streaming incident operation.');
            }
            if($action==='list'){
                return $this->json($out,['incidents'=>$this->incidents->list($org,$source)]);
            }
            $id=filter_var($in->getOption('incident-id'),FILTER_VALIDATE_INT);
            if($action==='ack'){
                if($id===false||$id<1||!$this->belongs($org,$source,$id)){
                    throw new DomainException('Incident not in source/tenant scope.');
                }
                return $this->json($out,['acknowledged'=>$this->incidents->acknowledge($org,$actor,$id)]);
            }
            if($action==='resolve'){
                if($id===false||$id<1||!($incident=$this->belongs($org,$source,$id))){
                    throw new DomainException('Incident not in source/tenant scope.');
                }
                $state=$this->health->evaluate($this->snapshots->inspect($org,$source));
                // Never close simply because a broken sensor reports no data.
                if($state['health']==='NO_EVIDENCE'){
                    throw new DomainException('Cannot resolve incident without current market evidence.');
                }
                foreach($state['alerts'] as $alert){
                    if($alert['code']===$incident['code']
                        &&($alert['source_id']??null)===$source
                        &&($alert['shard_key']??$alert['instrument_id']??'')
                            ===$incident['subject_id']){
                        throw new DomainException('Incident cause is still active.');
                    }
                }
                return $this->json($out,['resolved'=>$this->incidents->resolve(
                    $org,$id,$source,$incident['code'],
                )]);
            }
            if(getenv('COS_CM_STREAM_OPS_MONITOR_ENABLED')!=='1'){
                throw new DomainException('Operational incident scanner disabled by default.');
            }
            $email=(string)$in->getOption('alert-email');
            if(!filter_var($email,FILTER_VALIDATE_EMAIL)){
                throw new DomainException('Incident scanner needs explicit authorized alert recipient.');
            }
            $snapshot=$this->snapshots->inspect($org,$source);
            $health=$this->health->evaluate($snapshot);
            $changes=$this->incidents->detect($org,$health['alerts']);
            $sent=0;$failed=0;
            foreach($this->incidents->pendingNotifications($org,$source) as $incident){
                try{
                    $this->delivery->queue($org,$email,$incident);
                    $this->incidents->notificationQueued($org,(int)$incident['id']);
                    $sent++;
                }catch(Throwable){$failed++;}
            }
            $result=[
                'status'=>$failed===0?'MONITORED':'ALERT_DELIVERY_FAILED',
                'source_id'=>$source,'health'=>$health['health'],
                'alert_counts'=>$health['counts'],'incident_changes'=>$changes,
                'notifications_queued'=>$sent,'notifications_failed'=>$failed,
                'delivery_semantics'=>'OUTBOX_QUEUED_NOT_INBOX_DELIVERED',
            ];
            return $this->json($out,$result,$failed===0?Command::SUCCESS:Command::FAILURE);
        }catch(Throwable $e){
            return $this->json($out,['status'=>'REJECTED','type'=>get_class($e)],Command::FAILURE);
        }
    }

    /** @return array<string,mixed>|false */
    private function belongs(string $org,string $source,int $id):array|false
    {
        foreach($this->incidents->list($org,$source,500) as $incident){
            if((int)$incident['id']===$id)return $incident;
        }
        return false;
    }

    /** @param array<string,mixed> $data */
    private function json(OutputInterface $output,array $data,int $status=Command::SUCCESS):int
    {
        $output->writeln(json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        return $status;
    }
}
