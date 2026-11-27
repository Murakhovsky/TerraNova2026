<?php

declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\AI\AgentEvidence;
use App\Web\Experience\Dev\DataGridCatalogDemo;
use App\Web\Experience\Dev\WorkspaceShellPreviewController;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[AsCommand(name: 'cos:web:cuw-quality:fixture', description: 'Render synthetic, local-only CUW-F browser fixtures from real Twig components.')]
final class CuwQualityFixtureCommand extends Command
{
    public function __construct(
        private readonly Environment $twig,
        private readonly KernelInterface $kernel,
        private readonly RequestStack $requests,
        private readonly TranslatorInterface $translator,
        private readonly DataGridCatalogDemo $dataGrid,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('directory', InputArgument::REQUIRED, 'Local fixture output directory');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->kernel->getEnvironment() !== 'test' || !$this->translator instanceof LocaleAwareInterface) {
            $output->writeln('<error>CUW-F fixtures require the test environment.</error>');
            return self::FAILURE;
        }
        $directory = (string) $input->getArgument('directory');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            return self::FAILURE;
        }

        foreach (['uk', 'en'] as $locale) {
            $request = Request::create('/dev/ui?quality=1');
            $request->setLocale($locale);
            $request->setSession(new Session(new MockArraySessionStorage()));
            $this->requests->push($request);
            $this->translator->setLocale($locale);
            try {
                $html = $this->twig->render('experience/cuw_quality_catalog.html.twig', [
                    'dataGridDemo' => $this->dataGrid->build($request),
                    'fixtureMode' => true,
                    'qualityEvidence' => new AgentEvidence('DEMO evidence', [
                        'source' => 'fixture-only',
                        'reference' => 'DEMO-RECONCILIATION-2026-000001-A1234567890-B1234567890-C1234567890',
                    ]),
                ]);
                if (file_put_contents($directory . '/catalog-' . $locale . '.html', $html) === false) {
                    return self::FAILURE;
                }
                $html = (new WorkspaceShellPreviewController($this->twig))()->getContent();
                if (file_put_contents($directory . '/shell-' . $locale . '.html', (string) $html) === false) {
                    return self::FAILURE;
                }
            } finally {
                $this->requests->pop();
            }
        }
        $output->writeln('CUW-F synthetic catalog and Shell fixtures rendered (UK/EN).');
        return self::SUCCESS;
    }
}
