<?php
declare(strict_types=1);

namespace App\Command;

use App\Persistence\Federation\FederationHistoricalRunCutover;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Human operator chooses one tenant and Run, no fleet-wide implicit migration. */
#[AsCommand(name:'cos:federation:history:cutover',
    description:'Inspect or safely promote a pristine historical Federation Run (no Action replay).')]
final class FederationHistoricalCutoverCommand extends Command
{
    public function __construct(private readonly FederationHistoricalRunCutover $cutover) { parent::__construct(); }

    protected function configure():void
    {
        $this->addArgument('organization',InputArgument::REQUIRED,'Tenant organization');
        $this->addArgument('run',InputArgument::REQUIRED,'Legacy Run ID');
        $this->addOption('apply',null,InputOption::VALUE_NONE,'Commit only after strict parity and no Action evidence');
        $this->addOption('operator',null,InputOption::VALUE_REQUIRED,'Active tenant admin tn_users ID');
        $this->addOption('permit',null,InputOption::VALUE_REQUIRED,'Time-bound, tenant/Run/source-bound signed authorization');
    }

    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        $org=(string)$input->getArgument('organization');
        $run=(string)$input->getArgument('run');
        if ($input->getOption('apply')) {
            $operator=(string)$input->getOption('operator');
            $permit=(string)$input->getOption('permit');
            $result=$this->cutover->promotePristine($org,$run,$operator,$permit);
        } else {
            $result=$this->cutover->inspect($org,$run);
        }
        $output->writeln(json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        return (($result['promotable']??true)===false && ($result['migration_state']??'')!=='promoted')
            ? Command::FAILURE : Command::SUCCESS;
    }
}
