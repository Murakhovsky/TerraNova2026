<?php
declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\Governance\DesignSystemGapRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'cos:experience:design-system:gaps', description: 'Validate and report governed Design System gaps and Guardian decisions.')]
final class ExperienceDesignSystemGapsCommand extends Command
{
    public function __construct(private readonly DesignSystemGapRegistry $gaps) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE)->addOption('strict', null, InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $items = $this->gaps->all();
        $pending = $this->gaps->pending();
        $data = ['total' => count($items), 'pending' => count($pending), 'policy' => $this->gaps->policy(), 'items' => $items];

        if ($input->getOption('json')) {
            $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $io = new SymfonyStyle($input, $output);
            $io->title('COS Experience Design System Gaps');
            $io->definitionList(['Total' => count($items)], ['Pending Guardian decisions' => count($pending)]);
        }

        return Command::SUCCESS;
    }
}
