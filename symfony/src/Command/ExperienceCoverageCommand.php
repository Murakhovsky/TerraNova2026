<?php

declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\Registry\CompiledPageContractRegistry;
use App\Web\Experience\Registry\ExperienceRouteInventory;
use App\Web\Experience\Registry\PageExperienceStatus;
use App\Web\Experience\Registry\RouteExemptionRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cos:experience:coverage',
    description: 'Report COS Experience V1 contract and quality coverage.',
)]
final class ExperienceCoverageCommand extends Command
{
    public function __construct(
        private readonly ExperienceRouteInventory $inventory,
        private readonly CompiledPageContractRegistry $registry,
        private readonly RouteExemptionRegistry $exemptions,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $routes = $this->inventory->productionHtmlRoutes();
        $contracts = $this->registry->all();
        $exemptions = $this->exemptions->all();

        $implemented = 0;
        $v1Ready = 0;
        $byDomain = [];

        foreach ($contracts as $contract) {
            if (!in_array($contract->status, [
                PageExperienceStatus::Discovered,
                PageExperienceStatus::Inventoried,
                PageExperienceStatus::Contracted,
                PageExperienceStatus::Implementing,
            ], true)) {
                ++$implemented;
            }

            if ($contract->status === PageExperienceStatus::V1Ready && $contract->quality->isV1Ready()) {
                ++$v1Ready;
            }

            $byDomain[$contract->domain]['total'] = ($byDomain[$contract->domain]['total'] ?? 0) + 1;
            $byDomain[$contract->domain]['ready'] = ($byDomain[$contract->domain]['ready'] ?? 0)
                + (($contract->status === PageExperienceStatus::V1Ready && $contract->quality->isV1Ready()) ? 1 : 0);
        }

        ksort($byDomain);
        $covered = count($contracts) + count($exemptions);
        $routeCoverage = count($routes) > 0 ? (int) round(($covered / count($routes)) * 100) : 100;

        $io->title('COS Experience Coverage');
        $io->definitionList(
            ['Production HTML routes' => count($routes)],
            ['Page Contracts' => count($contracts)],
            ['Explicit exemptions' => count($exemptions)],
            ['Inventory coverage' => $routeCoverage . '%'],
            ['Implemented or later' => $implemented],
            ['Experience V1 ready' => $v1Ready],
        );

        $rows = [];
        foreach ($byDomain as $domain => $counts) {
            $percent = $counts['total'] > 0 ? (int) round(($counts['ready'] / $counts['total']) * 100) : 0;
            $rows[] = [$domain, $counts['ready'] . '/' . $counts['total'], $percent . '%'];
        }

        $io->section('By Domain');
        $io->table(['Domain', 'V1 ready', 'Coverage'], $rows);

        return Command::SUCCESS;
    }
}
