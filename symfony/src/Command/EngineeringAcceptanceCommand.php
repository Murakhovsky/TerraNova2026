<?php
declare(strict_types=1);

namespace App\Command;

use App\Engineering\Application\Acceptance\EngineeringV01AcceptanceService;
use App\Engineering\Domain\Workflow\EngineeringId;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cos:engineering:v01:acceptance', description: 'Verify persisted evidence for a COS Engineering Agents V0.1 release scenario.')]
final class EngineeringAcceptanceCommand extends Command
{
    public function __construct(private readonly EngineeringV01AcceptanceService $acceptance)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('feature', InputArgument::REQUIRED, 'Engineering feature UUID.')
            ->addOption('scenario', null, InputOption::VALUE_REQUIRED, 'success|fix-loop|human-gate|recovery', 'success')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print machine-readable JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $featureId = EngineeringId::assert(trim((string) $input->getArgument('feature')));
        $scenario = trim((string) $input->getOption('scenario'));
        $result = $this->acceptance->verify($featureId, $scenario);

        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return $result['passed'] ? Command::SUCCESS : Command::FAILURE;
        }

        $output->writeln(sprintf(
            'COS Engineering V0.1 acceptance [%s]: %s',
            $result['scenario'],
            $result['passed'] ? 'PASS' : 'FAIL',
        ));
        $output->writeln('Feature: '.$result['feature_id']);
        foreach ($result['checks'] as $check) {
            $output->writeln(sprintf('  [%s] %s — %s', $check['passed'] ? 'PASS' : 'FAIL', $check['id'], $check['detail']));
        }

        if (!$result['passed']) {
            $output->writeln('Blocking checks: '.implode(', ', $result['failures']));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
