<?php
declare(strict_types=1);

namespace App\Command;

use DomainException;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
use Domains\CapitalMarkets\Application\Service\MultiSymbolStreamingCore;
use Domains\CapitalMarkets\Model\CapitalMarketsCapability;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Opt-in process-level supervisor for independent source-fenced batch workers.
 * Never divides a single source into concurrent shards or assumes ACKs.
 * Existing workers own WS/protocol/recovery and durable MySQL leases.
 */
#[AsCommand(name:'cos:capital-markets:market-data:stream:multi-fleet',
    description:'Supervise bounded parallel BBO workers by independently fenced source.')]
final class CapitalMarketsMultiSymbolFleetCommand extends Command
{
    public function __construct(
        private readonly MultiSymbolStreamingCore $planner,
        private readonly CapitalMarketsAccessControlInterface $access,
    ){parent::__construct();}

    protected function configure():void
    {
        $this->addArgument('organization',InputArgument::REQUIRED,'Tenant organization ID')
            ->addArgument('actor',InputArgument::REQUIRED,'Authorized actor ID')
            ->addOption('sources',null,InputOption::VALUE_REQUIRED,'1..16 unique existing source IDs')
            ->addOption('seconds',null,InputOption::VALUE_REQUIRED,'Run duration, 0 until SIGTERM','60')
            ->addOption('max-workers',null,InputOption::VALUE_REQUIRED,'Maximum independent source workers (1..16)','16')
            ->addOption('execute',null,InputOption::VALUE_NONE,'Actually run child workers (default dry-run)');
    }

    protected function execute(InputInterface $in,OutputInterface $out):int
    {
        try{
            $org=trim((string)$in->getArgument('organization'));
            $actor=filter_var($in->getArgument('actor'),FILTER_VALIDATE_INT);
            $seconds=filter_var($in->getOption('seconds'),FILTER_VALIDATE_INT);
            $max=filter_var($in->getOption('max-workers'),FILTER_VALIDATE_INT);
            $raw=(string)$in->getOption('sources');
            if($org===''||strlen($org)>190||$actor===false||$actor<1||$raw===''
                ||$seconds===false||$seconds<0||$seconds>86400
                ||$max===false||$max<1||$max>16){
                throw new DomainException('Invalid bounded fleet scope.');
            }
            if(!$this->access->hasCapability($org,$actor,CapitalMarketsCapability::MarketDataManage->value)
                &&!$this->access->hasCapability($org,$actor,CapitalMarketsCapability::Manage->value)){
                throw new DomainException('Market Data manager capability required.');
            }
            $plan=$this->planner->plan($org,explode(',',$raw));
            if(!$in->getOption('execute')){
                $out->writeln(json_encode($plan,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
                return Command::SUCCESS;
            }
            if(getenv('COS_CM_STREAMING_MULTI_FLEET_ENABLED')!=='1'
                ||!function_exists('pcntl_async_signals')||!function_exists('proc_open')){
                throw new DomainException('Live fleet requires opt-in flag, pcntl and process supervisor support.');
            }
            $sources=$plan['sources'];
            if(count($sources)>$max){
                throw new DomainException('Fleet would omit selected sources: increase bounded --max-workers.');
            }
            // Only approved, literal source-scoped commands ever execute.
            foreach($sources as $source){
                $profile=MultiSymbolStreamingCore::provider($source['adapter_type']);
                if($profile['command']!==$source['worker_command']){
                    throw new DomainException('Unapproved fleet worker command.');
                }
            }
            $stop=false;
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM,static function()use(&$stop):void{$stop=true;});
            pcntl_signal(SIGINT,static function()use(&$stop):void{$stop=true;});
            $deadline=$seconds>0?microtime(true)+$seconds:null;
            $processes=[];$attempts=[];$restartAt=[];$lastLaunch=0.0;$exhausted=[];
            foreach($sources as $source){
                $id=$source['source_id'];$attempts[$id]=0;$restartAt[$id]=microtime(true);
            }
            try{
                while(!$stop && ($deadline===null||microtime(true)<$deadline)){
                    foreach($processes as $id=>$entry){
                        $status=proc_get_status($entry['handle']);
                        if(!is_array($status)||$status['running'])continue;
                        $this->dispose($entry);
                        unset($processes[$id]);
                        $attempts[$id]++;
                        if($attempts[$id]>5){
                            $exhausted[$id]='RESTART_BUDGET_EXHAUSTED';
                        }else{
                            $policy=$entry['reconnect'];
                            $backoff=min($policy['max_delay_ms'],
                                $policy['initial_delay_ms']*(2**min(8,$attempts[$id]-1)));
                            $restartAt[$id]=microtime(true)+$backoff/1000.0;
                        }
                    }
                    if(count($exhausted)>0){
                        // Fail the overall release, and terminate children:
                        // partial fleets cannot be mistaken for 100% coverage.
                        $stop=true;break;
                    }
                    foreach($sources as $source){
                        $id=$source['source_id'];
                        if(isset($processes[$id])||microtime(true)<$restartAt[$id])continue;
                        if(microtime(true)-$lastLaunch<0.5)break;
                        $command=[PHP_BINARY,'bin/console',$source['worker_command'],
                            $org,$id,'--seconds=0'];
                        $spec=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
                        $pipes=[];
                        $handle=proc_open($command,$spec,$pipes,
                            dirname(__DIR__,2));
                        if(!is_resource($handle)){
                            throw new DomainException('Cannot launch approved source worker.');
                        }
                        fclose($pipes[0]);
                        stream_set_blocking($pipes[1],false);
                        stream_set_blocking($pipes[2],false);
                        $processes[$id]=['handle'=>$handle,'pipes'=>$pipes,
                            'reconnect'=>$source['reconnect']];
                        $lastLaunch=microtime(true);
                        break; // Stagger each connection request.
                    }
                    foreach($processes as $entry){
                        // Drain without logging raw source payloads/credentials.
                        foreach($entry['pipes'] as $pipe){
                            if(is_resource($pipe))stream_get_contents($pipe,8192);
                        }
                    }
                    usleep(200000);
                }
            }finally{
                foreach($processes as $entry){
                    if(is_resource($entry['handle']))proc_terminate($entry['handle'],SIGTERM);
                }
                $grace=microtime(true)+8;
                do{
                    $running=false;
                    foreach($processes as $entry){
                        foreach($entry['pipes'] as $pipe){
                            if(is_resource($pipe))stream_get_contents($pipe,8192);
                        }
                        $status=proc_get_status($entry['handle']);
                        if(is_array($status)&&$status['running'])$running=true;
                    }
                    if($running && microtime(true)<$grace)usleep(200000);
                    else break;
                }while(true);
                foreach($processes as $entry){
                    $status=proc_get_status($entry['handle']);
                    if(is_array($status)&&$status['running']){
                        proc_terminate($entry['handle'],SIGKILL);
                    }
                    $this->dispose($entry);
                }
            }
            $result=['status'=>$exhausted===[]?'STOPPED':'FAILED',
                'planned_sources'=>count($sources),
                'planned_symbols'=>$plan['totals']['BBO'],
                'restart_counts'=>$attempts,'failed_sources'=>array_keys($exhausted),
                'actual_live_quote_quality'=>'NOT_ATTESTED_BY_SUPERVISOR'];
            $out->writeln(json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
            return $exhausted===[]?Command::SUCCESS:Command::FAILURE;
        }catch(Throwable $error){
            $out->writeln(json_encode(['status'=>'REJECTED','type'=>get_class($error)],
                JSON_THROW_ON_ERROR));
            return Command::FAILURE;
        }
    }

    /** @param array{handle:resource,pipes:array<int,resource>,reconnect:array<string,int>} $entry */
    private function dispose(array $entry):void
    {
        foreach($entry['pipes'] as $pipe)if(is_resource($pipe))fclose($pipe);
        if(is_resource($entry['handle']))proc_close($entry['handle']);
    }
}
