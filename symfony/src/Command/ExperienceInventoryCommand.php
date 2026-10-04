<?php

declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\Registry\CompiledPageContractRegistry;
use App\Web\Experience\Registry\ExperienceRouteInventory;
use App\Web\Experience\Registry\PageContractCompiler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cos:experience:inventory',
    description: 'Inventory production GET/HEAD HTML routes against the COS Experience Registry.',
)]
final class ExperienceInventoryCommand extends Command
{
    public function __construct(
        private readonly ExperienceRouteInventory $inventory,
        private readonly CompiledPageContractRegistry $registry,
        private readonly PageContractCompiler $compiler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Fail when a production HTML route has no Page Contract.')
            ->addOption('compile', null, InputOption::VALUE_NONE, 'Compile var/experience/manifest.json after validation.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = [];
        $missing = [];

        foreach ($this->inventory->productionHtmlRoutes() as $route) {
            $contract = $this->registry->findByRouteName($route['name']);
            $rows[] = [
                $route['name'],
                $route['path'],
                $contract?->id->value ?? '—',
                $contract?->status->value ?? 'DISCOVERED',
            ];

            if ($contract === null) {
                $missing[] = $route['name'];
            }
        }

        $io->title('COS Experience Inventory');
        $io->table(['Route', 'Path', 'Page Contract', 'Status'], $rows);
        $io->writeln(sprintf(
            '<info>%d</info> pages discovered; <info>%d</info> contracted; <comment>%d</comment> missing.',
            count($rows),
            count($rows) - count($missing),
            count($missing),
        ));

        if ($input->getOption('compile')) {
            $io->success('Manifest compiled: ' . $this->compiler->compile());
        }

        if ($missing !== []) {
            $io->warning('Missing contracts: ' . implode(', ', $missing));
        }

        return $input->getOption('strict') && $missing !== [] ? Command::FAILURE : Command::SUCCESS;
    }
}
