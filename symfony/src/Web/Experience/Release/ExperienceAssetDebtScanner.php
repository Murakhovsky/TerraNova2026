<?php
declare(strict_types=1);

namespace App\Web\Experience\Release;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ExperienceAssetDebtScanner
{
    private const ALLOWED_FRONTEND = [
        'frontend/spatial/spatial-viewer.js',
        'frontend/spatial/spatial-viewer.css',
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%/..')]
        private string $root,
    ) {}

    public function scan(): ExperienceAssetDebtReport
    {
        $deadCss = [];
        $deadJs = [];
        $legacy = [];

        foreach ($this->files($this->root.'/frontend') as $absolute) {
            $relative = $this->relative($absolute);
            if (in_array($relative, self::ALLOWED_FRONTEND, true)) {
                continue;
            }

            if (str_ends_with($relative, '.css')) {
                $deadCss[] = $relative;
            } elseif (str_ends_with($relative, '.js')) {
                $deadJs[] = $relative;
            } else {
                $legacy[] = $relative;
            }
        }

        foreach ([
            'symfony/assets/styles/domains/growth.css',
        ] as $relative) {
            if (is_file($this->root.'/'.$relative)) {
                $deadCss[] = $relative;
            }
        }

        foreach ([
            'tests/frontend/api_client.mjs',
        ] as $relative) {
            if (is_file($this->root.'/'.$relative)) {
                $legacy[] = $relative;
            }
        }

        sort($deadCss);
        sort($deadJs);
        sort($legacy);

        $vite = is_file($this->root.'/vite.config.js')
            ? (string) file_get_contents($this->root.'/vite.config.js')
            : '';

        $viteBoundary = str_contains($vite, "'spatial-viewer':")
            && !str_contains($vite, 'frontend/entrypoints/')
            && !str_contains($vite, 'frontend/features/')
            && !str_contains($vite, 'frontend/core/');

        $rootsPresent = is_file($this->root.'/symfony/assets/app.js')
            && is_file($this->root.'/symfony/assets/styles/app.css')
            && is_file($this->root.'/frontend/spatial/spatial-viewer.js')
            && is_file($this->root.'/frontend/spatial/spatial-viewer.css');

        return new ExperienceAssetDebtReport(
            deadCss: $deadCss,
            deadJs: $deadJs,
            legacyArtifacts: $legacy,
            viteBoundaryValid: $viteBoundary,
            canonicalRootsPresent: $rootsPresent,
        );
    }

    /** @return list<string> */
    private function files(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $result = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $result[] = $file->getPathname();
            }
        }

        return $result;
    }

    private function relative(string $absolute): string
    {
        return str_replace('\\', '/', ltrim(substr($absolute, strlen($this->root)), '/\\'));
    }
}
