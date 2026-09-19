<?php
declare(strict_types=1);

namespace App\Command;

use Kernel\Configuration\Service\ConfigurationValidator;
use Kernel\Module\DomainModuleRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cos:config:validate', description: 'Validate module-owned COS rules and policies for one organization.')]
final class ConfigurationValidateCommand extends Command
{
    public function __construct(
        private readonly DomainModuleRegistry $registry,
        private readonly ConfigurationValidator $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('organization', null, InputOption::VALUE_REQUIRED, 'Organization id.', 'default');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $organizationId = trim((string) $input->getOption('organization')) ?: 'default';
        $result = ['domains' => 0, 'rules' => 0, 'policies' => 0];

        foreach ($this->registry->modules() as $module) {
            $rules = $module->rules($organizationId);
            $policies = $module->policies($organizationId);
            $this->validator->validate($module, $organizationId, $rules, $policies);
            $result['domains']++;
            $result['rules'] += count($rules);
            $result['policies'] += count($policies);
        }

        $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return Command::SUCCESS;
    }
}
