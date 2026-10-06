<?php
declare(strict_types=1);

namespace App\Command;

use Domains\CapitalMarkets\Application\Service\MarketSourcePollingService;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name:'cos:capital-markets:market-data:poll',
    description:'Poll one configured Capital Markets data source and update canonical market state.'
)]
final class CapitalMarketsMarketDataPollCommand extends Command
{
    public function __construct(private readonly MarketSourcePollingService $polling)
    {
        parent::__construct();
    }

    protected function configure():void
    {
        $this
            ->addArgument('organization',InputArgument::REQUIRED,'Organization id.')
            ->addArgument('source',InputArgument::REQUIRED,'Market source id.')
            ->addOption('limit',null,InputOption::VALUE_REQUIRED,'Maximum instrument targets to poll.','100');
    }

    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        $organizationId=trim((string)$input->getArgument('organization'));
        $sourceId=trim((string)$input->getArgument('source'));
        $limit=max(1,min(1000,(int)$input->getOption('limit')));
        if($organizationId===''||$sourceId===''){
            $output->writeln(json_encode(['status'=>'INVALID_ARGUMENT'],JSON_THROW_ON_ERROR));
            return Command::INVALID;
        }

        $result=$this->polling->poll($organizationId,MarketSourceId::fromString($sourceId),$limit);
        $output->writeln(json_encode($result->toArray(),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));

        return $result->status==='FAILED'?Command::FAILURE:Command::SUCCESS;
    }
}
