<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Repository;

use App\Engineering\Application\Context\RepositoryFileReaderInterface;
use RuntimeException;

final readonly class LocalRepositoryFileReader implements RepositoryFileReaderInterface
{
    private const EXTENSIONS = ['php','md','yaml','yml','json','twig','js','mjs','sql','css','scss','html','xml'];
    private const FORBIDDEN_PREFIXES = ['.git/','vendor/','node_modules/','var/'];

    public function __construct(
        private string $repositoryRoot,
        private int $maxFiles = 20,
        private int $maxFileBytes = 65536,
        private int $maxTotalBytes = 524288,
    ) {}

    public function readMany(array $paths): array
    {
        $root = realpath($this->repositoryRoot);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('Engineering repository root is unavailable.');
        }

        $result = [];
        $used = 0;
        foreach (array_values(array_unique($paths)) as $path) {
            if (count($result) >= $this->maxFiles || $used >= $this->maxTotalBytes) break;
            if (!is_string($path)) continue;

            $safe = $this->safeRelativePath($path);
            $absolute = realpath($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $safe));
            if ($absolute === false || !is_file($absolute)) continue;

            $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
            if (!str_starts_with($absolute, $rootPrefix)) {
                throw new RuntimeException('Repository file escaped configured root.');
            }

            $size = (int) filesize($absolute);
            $remaining = min($this->maxFileBytes, $this->maxTotalBytes - $used);
            if ($remaining <= 0) break;

            $handle = fopen($absolute, 'rb');
            if ($handle === false) continue;
            $content = (string) fread($handle, $remaining);
            fclose($handle);

            $used += strlen($content);
            $result[] = [
                'path' => $safe,
                'content' => $content,
                'complete' => strlen($content) >= $size,
                'size' => $size,
                'sha256' => hash('sha256', $content),
            ];
        }

        return $result;
    }

    private function safeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, "\0")) {
            throw new RuntimeException('Unsafe repository file path.');
        }
        if ($path === '.env' || str_starts_with($path, '.env.')) {
            throw new RuntimeException('Environment files are forbidden repository context.');
        }
        foreach (self::FORBIDDEN_PREFIXES as $prefix) {
            if ($path === rtrim($prefix, '/') || str_starts_with($path, $prefix)) {
                throw new RuntimeException('Forbidden repository context path: '.$path);
            }
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, self::EXTENSIONS, true)) {
            throw new RuntimeException('Unsupported repository context file type: '.$path);
        }

        return $path;
    }
}
