<?php
declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\Golden\GoldenHumanReviewPacketBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cos:experience:golden:review',
    description: 'Generate the human product/UX review packet for the COS Golden Experience 8.',
)]
final class GoldenHumanReviewCommand extends Command
{
    public function __construct(private readonly GoldenHumanReviewPacketBuilder $packet)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output structured JSON instead of Markdown.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('json')) {
            $output->writeln(json_encode($this->packet->build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        (new SymfonyStyle($input, $output))->writeln($this->packet->markdown());
        return Command::SUCCESS;
    }
}
