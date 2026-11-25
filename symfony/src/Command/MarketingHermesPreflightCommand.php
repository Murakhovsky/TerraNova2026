<?php
declare(strict_types=1);
namespace App\Command;

use App\Infrastructure\Marketing\HermesAnalystOperatorPreflight;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'cos:marketing:hermes-preflight',description:'Read-only operator check; never starts a Hermes generation or grants release authority.')]
final class MarketingHermesPreflightCommand extends Command
{
    public function __construct(private readonly HermesAnalystOperatorPreflight $preflight){parent::__construct();}
    protected function configure():void
    {
        $this->addArgument('organization',InputArgument::REQUIRED,'Explicit tenant ID')
            ->addOption('probe',null,InputOption::VALUE_NONE,'Explicitly allow authenticated HTTPS GET toolset discovery');
    }
    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        $org=(string)$input->getArgument('organization');
        if(!preg_match('/^[A-Za-z0-9_.:-]{1,40}$/D',$org)){
            $output->writeln('Invalid tenant identifier.');return Command::INVALID;
        }
        $report=$this->preflight->inspect($org,(bool)$input->getOption('probe'));
        $output->writeln(json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        return $report['status']==='BLOCKED'?Command::FAILURE:Command::SUCCESS;
    }
}
