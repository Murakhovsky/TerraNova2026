<?php
declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\Golden\GoldenDecisionRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cos:experience:golden:decisions',
    description: 'Audit the source-controlled Golden Experience human decision ledger.',
)]
final class GoldenDecisionAuditCommand extends Command
{
    public function __construct(private readonly GoldenDecisionRegistry $decisions)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = [];
        foreach ($this->decisions->all() as $id => $decision) {
            $rows[] = [
                $id,
                $decision['decision'],
                $decision['actor'] ?? '—',
                $decision['decided_at'] ?? '—',
                count($decision['evidence'] ?? []),
            ];
        }

        $io->title('COS Golden Human Decisions');
        $io->table(['Page', 'Decision', 'Actor', 'Decided at', 'Evidence'], $rows);
        $io->writeln(sprintf('<info>%d</info> / <info>8</info> accepted.', $this->decisions->acceptedCount()));

        return Command::SUCCESS;
    }
}
