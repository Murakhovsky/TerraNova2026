<?php
declare(strict_types=1);

namespace App\Command;

use App\Persistence\Federation\FederationExecutionFleetAudit;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Explicit trusted operator CLI. No HTTP route, mutation or release approval. */
#[AsCommand(name:'cos:federation:execution-fleet',
    description:'Read-only bounded tenant Federation execution inventory; never authorizes cutover.')]
final class FederationExecutionFleetAuditCommand extends Command
{
    public function __construct(private readonly FederationExecutionFleetAudit $audit)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('organization',InputArgument::REQUIRED,'Tenant organization ID');
        $this->addOption('limit',null,InputOption::VALUE_REQUIRED,
            'Maximum history entries per cohort (1-100; truncation prevents acceptance)',100);
    }

    protected function execute(InputInterface $input,OutputInterface $output): int
    {
        $result=$this->audit->inspect(
            (string)$input->getArgument('organization'),
            (int)$input->getOption('limit'),
        );
        $output->writeln(json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        // Even a clean bounded inventory is not proof of all Domain outcomes,
        // worker traces, authorizations, live tenants or an approved cutover.
        return Command::FAILURE;
    }
}
