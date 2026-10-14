<?php
declare(strict_types=1);

namespace App\Command;

use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
use Domains\CapitalMarkets\Application\Service\BStocksSeedFileReader;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\BStocksSeedImportRepository;
use Domains\CapitalMarkets\Model\CapitalMarketsCapability;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** Safe historical XLSM/CSV import; default is read-only preview. */
#[AsCommand(name:'cos:capital-markets:instruments:import',
    description:'Preview or import unverified historical Binance bStocks evidence.')]
final class CapitalMarketsSeedImportCommand extends Command
{
    public function __construct(
        private readonly BStocksSeedFileReader $reader,
        private readonly BStocksSeedImportRepository $repository,
        private readonly CapitalMarketsAccessControlInterface $access,
    ) { parent::__construct(); }

    protected function configure():void
    {
        $this->addArgument('organization',InputArgument::REQUIRED,'Tenant organization ID')
            ->addArgument('actor',InputArgument::REQUIRED,'Authorized actor ID')
            ->addOption('file',null,InputOption::VALUE_REQUIRED,'Local XLSM/XLSX/CSV filepath')
            ->addOption('apply',null,InputOption::VALUE_NONE,'Write after successful validation; omitted for dry-run.');
    }

    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        $org=trim((string)$input->getArgument('organization'));
        $actor=filter_var($input->getArgument('actor'),FILTER_VALIDATE_INT);
        $file=(string)$input->getOption('file');
        if ($org==='' || strlen($org)>190 || $actor===false || $actor<1 || $file==='') {
            $output->writeln('INVALID_ARGUMENT');
            return Command::INVALID;
        }
        if (!$this->access->hasCapability($org,$actor,CapitalMarketsCapability::MarketDataManage->value)
            && !$this->access->hasCapability($org,$actor,CapitalMarketsCapability::Manage->value)) {
            $output->writeln('FORBIDDEN');
            return Command::FAILURE;
        }
        try {
            $seed=$this->reader->read($file);
            $dryRun=!$input->getOption('apply');
            $result=$dryRun?$this->repository->preview($org,$seed)
                :$this->repository->apply($org,$actor,$seed);
            $output->writeln(json_encode([
                'status'=>$dryRun?'DRY_RUN':'IMPORTED','source_sha256'=>$seed['sha256'],
                'source_as_of'=>$seed['source_as_of'],'format'=>$seed['format'],
                'statistics'=>$result,'identity_verified'=>false,
                'execution_eligible'=>false,
            ],JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return $result['conflicts'] > 0 ? Command::FAILURE : Command::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln(json_encode(['status'=>'FAILED','error_type'=>get_class($e),
                'reason'=>$e->getMessage()],JSON_THROW_ON_ERROR));
            return Command::FAILURE;
        }
    }
}
