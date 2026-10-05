<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Repository;

use App\Engineering\Application\Context\RepositoryContextMap;
use App\Engineering\Application\Context\RepositoryDiscoveryInterface;
use App\Engineering\Application\DTO\EngineeringRequest;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final readonly class LocalRepositoryDiscovery implements RepositoryDiscoveryInterface
{
    private const EXTENSIONS = ['php','md','yaml','yml','json','twig','js','mjs','sql'];
    private const EXCLUDED_DIRECTORIES = ['.git','vendor','node_modules','var','archive','coverage','cache','logs','tmp','uploads','dist','build','.idea'];

    public function __construct(
        private string $repositoryRoot,
        private int $maxFiles = 20,
        private int $maxFileBytes = 131072,
        private int $maxScannedFiles = 4000,
        private int $maxTotalReadBytes = 33554432,
        private int $maxScanMilliseconds = 8000,
    ) {
    }

    public function discover(EngineeringRequest $request): RepositoryContextMap
    {
        $root = realpath($this->repositoryRoot);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('Engineering repository root is unavailable.');
        }

        $keywords = $this->keywords($request->searchText());
        $ranked = [];
        $startedAt = hrtime(true);
        $scannedFiles = 0;
        $readBytes = 0;
        $maxScannedFiles = max(100, $this->maxScannedFiles);
        $maxTotalReadBytes = max($this->maxFileBytes, $this->maxTotalReadBytes);
        $maxScanMilliseconds = max(250, $this->maxScanMilliseconds);

        $directory = new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator(
            $directory,
            static function (SplFileInfo $current): bool {
                if ($current->isDir()) {
                    return !in_array($current->getFilename(), self::EXCLUDED_DIRECTORIES, true);
                }
                return in_array(strtolower($current->getExtension()), self::EXTENSIONS, true);
            },
        );

        foreach (new RecursiveIteratorIterator($filter) as $file) {
            if (++$scannedFiles > $maxScannedFiles || $this->elapsedMilliseconds($startedAt) >= $maxScanMilliseconds) {
                break;
            }
            if (!$file instanceof SplFileInfo || !$file->isFile()) continue;

            $fileSize = $file->getSize();
            if ($fileSize > $this->maxFileBytes) continue;
            if ($readBytes + $fileSize > $maxTotalReadBytes) break;

            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $content = @file_get_contents($file->getPathname());
            if ($content === false) continue;
            $readBytes += strlen($content);

            $score = $this->score($path, $content, $keywords);
            if ($score <= 0) continue;

            $ranked[] = [
                'path' => $path,
                'score' => $score,
                'snippet' => $this->snippet($content, $keywords),
                'size' => $file->getSize(),
            ];
        }

        usort($ranked, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['path'], $b['path']));
        $files = array_slice($ranked, 0, max(1, $this->maxFiles));

        return new RepositoryContextMap(
            repositoryRevision: $this->revision($root),
            files: $files,
            domains: $this->pathsMatching($files, '/(^|\/)app\/Domains\/([^\/]+)/', 2),
            modules: $this->pathsMatching($files, '/(^|\/)(Kernel|Platform|Engineering)(\/|$)/', 2),
            routes: $this->filterPaths($files, static fn (string $p): bool => str_contains($p, 'route') || str_contains($p, 'Controller')),
            controllers: $this->filterPaths($files, static fn (string $p): bool => str_contains($p, 'Controller')),
            services: $this->filterPaths($files, static fn (string $p): bool => str_contains($p, 'Service')),
            repositories: $this->filterPaths($files, static fn (string $p): bool => str_contains($p, 'Repository')),
            entities: $this->filterPaths($files, static fn (string $p): bool => str_contains($p, '/Entity/') || str_contains($p, '/Model/')),
            frontend: $this->filterPaths($files, static fn (string $p): bool => str_contains($p, '/assets/') || str_contains($p, '/templates/')),
            tests: $this->filterPaths($files, static fn (string $p): bool => str_starts_with($p, 'tests/') || str_contains($p, '/tests/')),
            documentation: $this->filterPaths($files, static fn (string $p): bool => str_starts_with($p, 'docs/') || str_ends_with($p, '.md')),
        );
    }

    /** @return list<string> */
    private function keywords(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}_\/-]{3,}/u', mb_strtolower($text), $matches);
        $stop = array_fill_keys(['add','the','and','for','with','from','this','that','user','users','додати','для','та','або','який','яка','яке','потрібно'], true);
        $keywords = [];
        foreach ($matches[0] ?? [] as $word) {
            $word = trim($word, "-_/ ");
            if ($word === '' || isset($stop[$word])) continue;
            $keywords[$word] = true;
        }
        return array_slice(array_keys($keywords), 0, 12);
    }

    /** @param list<string> $keywords */
    private function score(string $path, string $content, array $keywords): int
    {
        $pathLower = mb_strtolower($path);
        $contentLower = mb_strtolower($content);
        $score = 0;
        foreach ($keywords as $keyword) {
            if (str_contains($pathLower, $keyword)) $score += 12;
            $count = substr_count($contentLower, $keyword);
            $score += min(8, $count);
        }
        if (str_starts_with($path, 'symfony/src/') || str_starts_with($path, 'app/')) $score += 2;
        if (str_starts_with($path, 'tests/')) $score += 1;
        return $score;
    }

    /** @param list<string> $keywords */
    private function snippet(string $content, array $keywords): string
    {
        $position = null;
        $lower = mb_strtolower($content);
        foreach ($keywords as $keyword) {
            $candidate = mb_strpos($lower, $keyword);
            if ($candidate !== false && ($position === null || $candidate < $position)) $position = $candidate;
        }
        $position ??= 0;
        $start = max(0, $position - 300);
        return trim(mb_substr($content, $start, 1200));
    }

    private function revision(string $root): string
    {
        $head = $root.'/.git/HEAD';
        if (!is_file($head)) return 'unknown';
        $value = trim((string) file_get_contents($head));
        if (!str_starts_with($value, 'ref: ')) return $value;
        $ref = $root.'/.git/'.substr($value, 5);
        return is_file($ref) ? trim((string) file_get_contents($ref)) : 'unknown';
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }

    private function filterPaths(array $files, callable $predicate): array
    {
        return array_values(array_map(
            static fn (array $file): string => $file['path'],
            array_filter($files, static fn (array $file): bool => $predicate($file['path'])),
        ));
    }

    private function pathsMatching(array $files, string $pattern, int $group): array
    {
        $values = [];
        foreach ($files as $file) {
            if (preg_match($pattern, $file['path'], $match) && isset($match[$group])) $values[$match[$group]] = true;
        }
        return array_keys($values);
    }
}
