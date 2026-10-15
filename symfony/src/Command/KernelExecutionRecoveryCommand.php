<?php
declare(strict_types=1);
namespace App\Command;

use App\Persistence\Workflow\DoctrineKernelExecutionRecoveryInspector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Operator inspection only. Cannot impersonate a manager or authorize replay. */
#[AsCommand(name:'cos:workflow:kernel-recovery',
 description:'Inspect native Agent/Tool canonical receipts and expired claims; read-only.')]
final class KernelExecutionRecoveryCommand extends Command
{
    public function __construct(
        private readonly DoctrineKernelExecutionRecoveryInspector $inspector,
    ) {parent::__construct();}
    protected function configure():void
    {
        $this->addArgument('organization',InputArgument::REQUIRED,'Tenant organization ID');
        $this->addOption('limit',null,InputOption::VALUE_REQUIRED,
            'Maximum Run rows to inspect',100);
    }
    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        $report=$this->inspector->inspectTenant(
            (string)$input->getArgument('organization'),
            (int)$input->getOption('limit'),
        );
        $output->writeln(json_encode(
            $report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        // A diagnostic must never accidentally be interpreted as an approval.
        return $report['status']==='technical_read_only_consistent'
            ? Command::SUCCESS : Command::FAILURE;
    }
}
