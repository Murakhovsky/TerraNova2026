<?php
declare(strict_types=1);

namespace App\Engineering\Application\DomainDevelopment;

use App\Engineering\Application\Repository\EngineeringRepositoryGatewayInterface;

final readonly class EngineeringRepositoryContextIndex
{
    public function __construct(private EngineeringRepositoryGatewayInterface $repository) {}

    /** @return array<string,mixed> */
    public function build(?string $revision = null): array
    {
        $revision = trim((string) $revision);
        if ($revision === '') $revision = $this->repository->currentBaseRevision();

        $index = [
            'revision' => $revision,
            'repository' => $this->repository->configuredRepository(),
            'classes' => [],
            'interfaces' => [],
            'services' => [],
            'entities' => [],
            'routes' => [],
            'migrations' => [],
            'tests' => [],
            'modules' => [],
            'dependencies' => [],
        ];

        foreach ($this->repository->repositoryTree($revision) as $entry) {
            if (($entry['type'] ?? null) !== 'blob') continue;
            $path = (string) ($entry['path'] ?? '');
            if ($path === '') continue;
            $base = basename($path);
            $php = str_ends_with(strtolower($path), '.php');

            if ($php && preg_match('/Interface\.php$/', $base) === 1) {
                $index['interfaces'][] = $this->item($path, $entry, substr($base, 0, -4));
            } elseif ($php && preg_match('/(?:Service|Manager|Coordinator|Scheduler|Resolver|Builder|Factory|Provider|Gateway|Registry|Engine)\.php$/', $base) === 1) {
                $index['services'][] = $this->item($path, $entry, substr($base, 0, -4));
            } elseif ($php && preg_match('/(?:Entity|Aggregate|Model|Record)\.php$/', $base) === 1) {
                $index['entities'][] = $this->item($path, $entry, substr($base, 0, -4));
            } elseif ($php && !str_contains($path, '/tests/') && !str_starts_with($path, 'tests/')) {
                $index['classes'][] = $this->item($path, $entry, substr($base, 0, -4));
            }

            if (
                $path === 'symfony/config/routes.yaml'
                || str_contains($path, '/routes/')
                || preg_match('#(?:^|/)routes?\.(?:ya?ml|php|xml)$#i', $path) === 1
            ) {
                $index['routes'][] = $this->item($path, $entry);
            }
            if (
                str_contains($path, '/migrations/')
                || str_starts_with($path, 'symfony/migrations/')
                || preg_match('/Version\d+\.php$/', $base) === 1
            ) {
                $index['migrations'][] = $this->item($path, $entry);
            }
            if (
                str_starts_with($path, 'tests/')
                || str_contains($path, '/tests/')
                || preg_match('/(?:Test|Spec)\.php$/', $base) === 1
            ) {
                $index['tests'][] = $this->item($path, $entry);
            }
            if (
                preg_match('#(?:^|/)(?:module|manifest)\.(?:json|ya?ml|php)$#i', $path) === 1
                || str_contains($path, '/Module/')
                || str_contains($path, '/Modules/')
            ) {
                $index['modules'][] = $this->item($path, $entry);
            }
            if (in_array($path, [
                'composer.json','composer.lock','symfony/composer.json','symfony/composer.lock',
                'package.json','package-lock.json','pnpm-lock.yaml','yarn.lock',
            ], true)) {
                $index['dependencies'][] = $this->item($path, $entry);
            }
        }

        foreach (array_keys($index) as $key) {
            if (!is_array($index[$key])) continue;
            usort($index[$key], static fn (array $a, array $b): int => strcmp((string) $a['path'], (string) $b['path']));
        }

        $index['counts'] = [];
        foreach (['classes','interfaces','services','entities','routes','migrations','tests','modules','dependencies'] as $key) {
            $index['counts'][$key] = count($index[$key]);
        }
        $index['hash'] = hash('sha256', json_encode($index['counts'], JSON_THROW_ON_ERROR).':'.$revision);

        return $index;
    }

    /** @param array<string,mixed> $entry @return array<string,mixed> */
    private function item(string $path, array $entry, ?string $symbol = null): array
    {
        return array_filter([
            'path' => $path,
            'symbol' => $symbol,
            'sha' => $entry['sha'] ?? null,
            'size' => $entry['size'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
