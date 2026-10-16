<?php
declare(strict_types=1);
namespace App\Command;

use DateTimeImmutable;
use DomainException;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
use Domains\CapitalMarkets\Application\Service\RawMarketReplaySimulator;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\{
    MysqlCrossVenueBboReconciliation,MysqlRawMarketProvenance
};
use Domains\CapitalMarkets\Model\CapitalMarketsCapability;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** Authenticated, tenant-scoped operator diagnostics: no quote injection. */
#[AsCommand(name:'cos:capital-markets:market-data:evidence',
    description:'Inspect independent venue consensus, provenance and read-only REPLAY.')]
final class CapitalMarketsStreamingEvidenceCommand extends Command
{
    public function __construct(
        private readonly MysqlCrossVenueBboReconciliation $consensus,
        private readonly MysqlRawMarketProvenance $provenance,
        private readonly RawMarketReplaySimulator $replay,
        private readonly CapitalMarketsAccessControlInterface $access,
    ){parent::__construct();}

    protected function configure():void
    {
        $this->addArgument('action',InputArgument::REQUIRED,'reconcile|provenance|replay')
            ->addArgument('organization',InputArgument::REQUIRED,'Tenant')
            ->addArgument('actor',InputArgument::REQUIRED,'Authorized operator')
            ->addOption('source',null,InputOption::VALUE_REQUIRED,'Canonical source ID')
            ->addOption('instrument',null,InputOption::VALUE_REQUIRED,'Same canonical instrument ID')
            ->addOption('max-bps',null,InputOption::VALUE_REQUIRED,'Tolerance (1..500)','30')
            ->addOption('cursor',null,InputOption::VALUE_REQUIRED,'Raw id cursor','0')
            ->addOption('limit',null,InputOption::VALUE_REQUIRED,'1..200','100')
            ->addOption('from',null,InputOption::VALUE_REQUIRED,'UTC ISO-8601')
            ->addOption('to',null,InputOption::VALUE_REQUIRED,'UTC ISO-8601')
            ->addOption('record',null,InputOption::VALUE_NONE,'Append immutable reconciliation receipt only');
    }

    protected function execute(InputInterface $in,OutputInterface $out):int
    {
        try{
            $action=(string)$in->getArgument('action');
            $org=trim((string)$in->getArgument('organization'));
            $actor=filter_var($in->getArgument('actor'),FILTER_VALIDATE_INT);
            if($org===''||strlen($org)>190||$actor===false||$actor<1
                ||!in_array($action,['reconcile','provenance','replay'],true)){
                throw new DomainException('Explicit tenant/actor and action required.');
            }
            $cap=$action==='replay'?CapitalMarketsCapability::MarketDataReplayManage->value:
                ($action==='provenance'?CapitalMarketsCapability::MarketDataHistoryView->value:
                CapitalMarketsCapability::MarketDataView->value);
            if(!$this->access->hasCapability($org,$actor,$cap)
               &&!$this->access->hasCapability($org,$actor,CapitalMarketsCapability::Manage->value)){
                throw new DomainException('Missing market evidence capability.');
            }
            if($action==='reconcile'){
                $id=(string)$in->getOption('instrument');
                $bps=filter_var($in->getOption('max-bps'),FILTER_VALIDATE_INT);
                if($bps===false)throw new DomainException('Invalid bps tolerance.');
                $report=$this->consensus->inspect($org,$id,$bps);
                if($in->getOption('record')){
                    if(!$this->access->hasCapability($org,$actor,
                        CapitalMarketsCapability::MarketDataManage->value)
                        &&!$this->access->hasCapability($org,$actor,
                            CapitalMarketsCapability::Manage->value)){
                        throw new DomainException('Immutable review receipt requires manage capability.');
                    }
                    $report['review_id']=$this->consensus->record($org,$report);
                }
            }else{
                if($in->getOption('record'))throw new DomainException('Provenance and replay cannot write.');
                $source=(string)$in->getOption('source');
                $limit=filter_var($in->getOption('limit'),FILTER_VALIDATE_INT);
                if($limit===false)throw new DomainException('Invalid bounded report limit.');
                if($action==='provenance'){
                    $cursor=filter_var($in->getOption('cursor'),FILTER_VALIDATE_INT);
                    if($cursor===false)throw new DomainException('Invalid raw cursor.');
                    $report=$this->provenance->verify($org,$source,$cursor,$limit);
                }else{
                    $from=(string)$in->getOption('from');$to=(string)$in->getOption('to');
                    if($from===''||$to===''||!str_ends_with($from,'Z')
                        ||!str_ends_with($to,'Z')){
                        throw new DomainException('Replay requires explicit UTC ISO-8601 window.');
                    }
                    $report=$this->replay->run($org,$source,
                        new DateTimeImmutable($from),new DateTimeImmutable($to),$limit);
                }
            }
            $out->writeln(json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
            $failureStatuses=['TAMPERED','INCOMPLETE','NO_EVIDENCE',
                'REPLAY_INCOMPLETE','REPLAY_PARTIAL'];
            return in_array($report['status']??'', $failureStatuses,true)
                ?Command::FAILURE:Command::SUCCESS;
        }catch(Throwable $e){
            $out->writeln(json_encode(['status'=>'REJECTED','type'=>get_class($e)],JSON_THROW_ON_ERROR));
            return Command::FAILURE;
        }
    }
}
