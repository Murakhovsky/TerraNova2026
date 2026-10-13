<?php
declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\Governance\UxDebtRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'cos:experience:ux-debt', description: 'Report governed COS Experience UX debt.')]
final class ExperienceUxDebtCommand extends Command
{
    public function __construct(private readonly UxDebtRegistry $debt) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE)->addOption('strict', null, InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $all = $this->debt->all();
        $open = $this->debt->open();
        $blocking = $this->debt->blocking();
        $data = ['total' => count($all), 'open' => count($open), 'blocking' => count($blocking), 'items' => $open];

        if ($input->getOption('json')) {
            $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $io = new SymfonyStyle($input, $output);
            $io->title('COS Experience UX Debt');
            $io->definitionList(['Total' => count($all)], ['Open' => count($open)], ['Blocking' => count($blocking)]);
        }

        return $input->getOption('strict') && $blocking !== [] ? Command::FAILURE : Command::SUCCESS;
    }
}
