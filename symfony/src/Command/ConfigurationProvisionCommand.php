<?php
declare(strict_types=1);

namespace App\Command;

use Kernel\Configuration\Service\ConfigurationProvisioner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cos:config:provision', description: 'Provision canonical COS module configuration for one organization.')]
final class ConfigurationProvisionCommand extends Command
{
    public function __construct(private readonly ConfigurationProvisioner $provisioner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('organization', null, InputOption::VALUE_REQUIRED, 'Organization id.', 'default')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Actor id recorded with the provision operation.', 'cli');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $organizationId = trim((string) $input->getOption('organization')) ?: 'default';
        $actorId = trim((string) $input->getOption('actor')) ?: 'cli';
        $output->writeln(json_encode(
            $this->provisioner->provision($organizationId, $actorId),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
        return Command::SUCCESS;
    }
}
