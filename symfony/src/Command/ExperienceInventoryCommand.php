<?php

declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\Registry\CompiledPageContractRegistry;
use App\Web\Experience\Registry\ExperienceRouteInventory;
use App\Web\Experience\Registry\PageContractCompiler;
use App\Web\Experience\Registry\RouteExemptionRegistry;
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
        private readonly RouteExemptionRegistry $exemptions,
        private readonly PageContractCompiler $compiler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Fail when a production HTML route has neither a Page Contract nor an explicit exemption.')
            ->addOption('compile', null, InputOption::VALUE_NONE, 'Compile var/experience/manifest.json after validation.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = [];
        $missing = [];
        $mismatched = [];
        $contracted = 0;
        $exempted = 0;

        foreach ($this->inventory->productionHtmlRoutes() as $route) {
            $contract = $this->registry->findByRouteName($route['name']);
            $exemption = $this->exemptions->find($route['name']);

            if ($contract !== null && $exemption !== null) {
                $mismatched[] = $route['name'] . ' is both contracted and exempted';
            }

            if ($contract !== null) {
                ++$contracted;
                if ($contract->path !== $route['path']) {
                    $mismatched[] = sprintf('%s path mismatch: registry=%s router=%s', $route['name'], $contract->path, $route['path']);
                }
                $rows[] = [$route['name'], $route['path'], $contract->id->value, $contract->status->value];
                continue;
            }

            if ($exemption !== null) {
                ++$exempted;
                if ($exemption->path !== $route['path']) {
                    $mismatched[] = sprintf('%s exemption path mismatch: registry=%s router=%s', $route['name'], $exemption->path, $route['path']);
                }
                $rows[] = [$route['name'], $route['path'], 'EXEMPT', $exemption->reason];
                continue;
            }

            $missing[] = $route['name'];
            $rows[] = [$route['name'], $route['path'], '—', 'DISCOVERED'];
        }

        $routeNames = array_column($this->inventory->productionHtmlRoutes(), 'name');
        foreach ($this->registry->all() as $contract) {
            if (!in_array($contract->routeName, $routeNames, true)) {
                $mismatched[] = $contract->routeName . ' exists in Page Registry but not in production HTML inventory';
            }
        }
        foreach ($this->exemptions->all() as $exemption) {
            if (!in_array($exemption->routeName, $routeNames, true)) {
                $mismatched[] = $exemption->routeName . ' is exempted but not in production HTML inventory';
            }
        }

        $io->title('COS Experience Inventory');
        $io->table(['Route', 'Path', 'Page Contract', 'Status'], $rows);
        $io->writeln(sprintf(
            '<info>%d</info> routes discovered; <info>%d</info> contracted; <info>%d</info> exempted; <comment>%d</comment> missing; <comment>%d</comment> mismatched.',
            count($rows),
            $contracted,
            $exempted,
            count($missing),
            count($mismatched),
        ));

        if ($input->getOption('compile')) {
            $io->success('Manifest compiled: ' . $this->compiler->compile());
        }

        if ($missing !== []) {
            $io->warning('Missing contracts/exemptions: ' . implode(', ', $missing));
        }
        if ($mismatched !== []) {
            $io->error($mismatched);
        }

        return $input->getOption('strict') && ($missing !== [] || $mismatched !== [])
            ? Command::FAILURE
            : Command::SUCCESS;
    }
}
