<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'cos:web:pwa:smoke',
    description: 'Validate canonical COS installable PWA foundation.',
)]
final class PwaFoundationSmokeCommand extends Command
{
    public function __construct(
        private readonly KernelInterface $kernel,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectDir = $this->kernel->getProjectDir();
        $publicDir = $projectDir . '/public';

        foreach ([
            'manifest.webmanifest',
            'sw.js',
            'offline.html',
            'icons/cos-192.svg',
            'icons/cos-512.svg',
            'icons/cos-maskable.svg',
        ] as $relative) {
            if (!is_file($publicDir . '/' . $relative)) {
                $output->writeln(sprintf('<error>PWA artifact is missing: %s</error>', $relative));

                return Command::FAILURE;
            }
        }

        $manifest = json_decode((string) file_get_contents($publicDir . '/manifest.webmanifest'), true);
        if (!is_array($manifest)) {
            $output->writeln('<error>PWA manifest is not valid JSON.</error>');

            return Command::FAILURE;
        }

        foreach ([
            'name' => 'COS — Company Operating System',
            'short_name' => 'COS',
            'start_url' => '/admin',
            'scope' => '/',
            'display' => 'standalone',
        ] as $key => $expected) {
            if (($manifest[$key] ?? null) !== $expected) {
                $output->writeln(sprintf('<error>PWA manifest contract failed: %s</error>', $key));

                return Command::FAILURE;
            }
        }

        $icons = $manifest['icons'] ?? [];
        if (!is_array($icons) || count($icons) < 3) {
            $output->writeln('<error>PWA installability icons are incomplete.</error>');

            return Command::FAILURE;
        }

        $worker = (string) file_get_contents($publicDir . '/sw.js');
        foreach ([
            "request.method !== 'GET'",
            "request.mode !== 'navigate'",
            'fetch(request).catch',
            "cache.match(OFFLINE_URL)",
            "event.data?.type === 'SKIP_WAITING'",
        ] as $marker) {
            if (!str_contains($worker, $marker)) {
                $output->writeln(sprintf('<error>PWA worker contract is missing: %s</error>', $marker));

                return Command::FAILURE;
            }
        }

        foreach (['indexedDB', 'localStorage', 'sessionStorage', 'BackgroundSync', '/api/'] as $forbidden) {
            if (str_contains($worker, $forbidden)) {
                $output->writeln(sprintf('<error>PWA worker contains forbidden offline business state: %s</error>', $forbidden));

                return Command::FAILURE;
            }
        }

        $offline = (string) file_get_contents($publicDir . '/offline.html');
        if (!str_contains($offline, 'data-cos-offline-fallback') || !str_contains($offline, 'online-first')) {
            $output->writeln('<error>Canonical static offline fallback is invalid.</error>');

            return Command::FAILURE;
        }

        $output->writeln('COS PWA Foundation runtime passed.');

        return Command::SUCCESS;
    }
}
