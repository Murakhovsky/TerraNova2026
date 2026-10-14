<?php
declare(strict_types=1);

namespace App\Command;

use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
use Domains\CapitalMarkets\Application\Service\BStocksCanonicalDraftService;
use Domains\CapitalMarkets\Model\CapitalMarketsCapability;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name:'cos:capital-markets:instruments:seed-drafts',
    description:'Preview or register historical bStock DRAFT instruments via audited canonical domain.')]
final class CapitalMarketsCanonicalDraftCommand extends Command
{
    public function __construct(
        private readonly BStocksCanonicalDraftService $drafts,
        private readonly CapitalMarketsAccessControlInterface $access,
    ) { parent::__construct(); }

    protected function configure():void
    {
        $this->addArgument('organization',InputArgument::REQUIRED,'Tenant organization ID')
            ->addArgument('actor',InputArgument::REQUIRED,'Authorized instrument manager ID')
            ->addOption('apply',null,InputOption::VALUE_NONE,'Create DRAFT canonical instruments after dry-run.')
            ->addOption('underlyings',null,InputOption::VALUE_NONE,'Use separate equity/ETF reference drafts instead of token drafts.')
            ->addOption('limit',null,InputOption::VALUE_REQUIRED,'Max imported rows (1..500)','100');
    }

    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        $org=trim((string)$input->getArgument('organization'));
        $actor=filter_var($input->getArgument('actor'),FILTER_VALIDATE_INT);
        $limit=filter_var($input->getOption('limit'),FILTER_VALIDATE_INT);
        if ($org==='' || strlen($org)>190 || $actor===false || $actor<1
            || $limit===false || $limit<1 || $limit>500) {
            $output->writeln('INVALID_ARGUMENT');
            return Command::INVALID;
        }
        if (!$this->access->hasCapability($org,$actor,CapitalMarketsCapability::InstrumentManage->value)
            && !$this->access->hasCapability($org,$actor,CapitalMarketsCapability::Manage->value)) {
            $output->writeln('FORBIDDEN');
            return Command::FAILURE;
        }
        try {
            $reference=(bool)$input->getOption('underlyings');
            $result=$input->getOption('apply')
                ?($reference?$this->drafts->applyUnderlyings($org,$actor,$limit)
                    :$this->drafts->apply($org,$actor,$limit))
                :['status'=>'DRY_RUN','plan'=>$reference?$this->drafts->planUnderlying($org):$this->drafts->plan($org)];
            $output->writeln(json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
            return ($result['plan']['blocked']??0)>0 ? Command::FAILURE : Command::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln(json_encode(['status'=>'FAILED','reason'=>$e->getMessage()],JSON_THROW_ON_ERROR));
            return Command::FAILURE;
        }
    }
}
