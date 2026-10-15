<?php
declare(strict_types=1);

namespace App\Command;

use Domains\CapitalMarkets\Application\Service\BStocksCanonicalDraftProjection;
use Domains\CapitalMarkets\Application\Service\TokenizedEquityUniverseScanner;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\BStocksSeedImportRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name:'cos:capital-markets:opportunity:bstocks-scan',
    description:'Scan only explicitly imported bStock universe through existing verified H1/H2 vertical slice.')]
final class CapitalMarketsBStocksOpportunityScanCommand extends Command
{
    public function __construct(
        private readonly BStocksSeedImportRepository $seeds,
        private readonly BStocksCanonicalDraftProjection $identity,
        private readonly TokenizedEquityUniverseScanner $scanner,
    ) {parent::__construct();}

    protected function configure():void
    {
        $this->addArgument('organization',InputArgument::REQUIRED,'Tenant ID')
            ->addOption('max-instruments',null,InputOption::VALUE_REQUIRED,'Fail closed above this universe size (1..500)','100');
    }

    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        $org=trim((string)$input->getArgument('organization'));
        $cap=filter_var($input->getOption('max-instruments'),FILTER_VALIDATE_INT);
        if($org===''||strlen($org)>190||$cap===false||$cap<1||$cap>500)return Command::INVALID;
        if(getenv('COS_CM_BSTOCKS_OPPORTUNITY_ENABLED')!=='1'){
            $output->writeln('POOL_OPPORTUNITY_DISABLED');return Command::FAILURE;
        }
        try {
            $seeds=$this->seeds->listForCanonicalDraft($org);
            if($seeds===[]||count($seeds)>$cap){
                $output->writeln('IMPORT_REQUIRED_OR_POOL_CAP_EXCEEDED');return Command::FAILURE;
            }
            $tokens=[];$parents=[];
            foreach($seeds as $seed) {
                $token=$this->identity->createInput($org,$seed);
                $underlying=$this->identity->createUnderlyingInput($org,$seed);
                $tokens[$token['id']]=true;
                $parents[$underlying['id']]=true;
            }
            $report=$this->scanner->scan($org,[
                'universe'=>[
                    'id'=>'BINANCE_BSTOCKS_SEED','name'=>'Verified bStocks Market Pool',
                    'status'=>'ACTIVE','enabled'=>true,
                    'underlying_instrument_ids'=>array_keys($parents),
                    'tokenized_instrument_ids'=>array_keys($tokens),
                    'venues'=>[],'hypotheses'=>['H1','H2'],
                ],
            ]);
            $output->writeln(json_encode([
                'mode'=>'VERIFIED_POOL_ONLY','seed_count'=>count($seeds),
                'h1_scans'=>$report['h1_scans'],'h2_scans'=>$report['h2_scans'],
                'errors'=>$report['errors'],
                'execution_eligible_not_guaranteed'=>true,
            ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }catch(Throwable $error){
            $output->writeln(json_encode(['status'=>'FAILED','type'=>get_class($error)],JSON_THROW_ON_ERROR));
            return Command::FAILURE;
        }
    }
}
