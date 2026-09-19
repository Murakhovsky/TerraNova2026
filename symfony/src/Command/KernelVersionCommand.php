<?php
declare(strict_types=1);

namespace App\Command;

use Kernel\Module\KernelVersion;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cos:kernel:version', description: 'Print the canonical COS Kernel version.')]
final class KernelVersionCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(KernelVersion::VERSION);
        return Command::SUCCESS;
    }
}
