<?php
declare(strict_types=1);

namespace App\Command;

use Domains\Spatial\Application\Contract\SpatialProcessingInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'cos:spatial:process',
    description: 'Process queued Spatial assets using the canonical Spatial runtime.',
)]
final class SpatialProcessingCommand extends Command
{
    public function __construct(private readonly SpatialProcessingInterface $processing)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum queued jobs to process.', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, min(50, (int) $input->getOption('limit')));
        $output->writeln(json_encode(
            $this->processing->process($limit),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));

        return Command::SUCCESS;
    }
}
