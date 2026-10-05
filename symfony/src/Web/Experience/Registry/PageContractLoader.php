<?php

declare(strict_types=1);

namespace App\Web\Experience\Registry;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

final readonly class PageContractLoader
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/../resources/experience/pages')]
        private string $pagesDirectory,
    ) {
    }

    /** @return list<PageContract> */
    public function load(): array
    {
        if (!is_dir($this->pagesDirectory)) {
            return [];
        }

        $files = $this->yamlFiles($this->pagesDirectory);
        $contracts = [];

        foreach ($files as $file) {
            $document = Yaml::parseFile($file);
            if (!is_array($document)) {
                throw new RuntimeException('Invalid Experience Registry YAML: ' . $file);
            }

            $pages = isset($document['pages']) && is_array($document['pages'])
                ? $document['pages']
                : [$document];

            foreach ($pages as $page) {
                if (!is_array($page)) {
                    throw new RuntimeException('Page contract entry must be a mapping: ' . $file);
                }

                $contracts[] = PageContract::fromArray($page);
            }
        }

        return $contracts;
    }

    /** @return list<string> */
    private function yamlFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            if (in_array(strtolower($file->getExtension()), ['yaml', 'yml'], true)) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
