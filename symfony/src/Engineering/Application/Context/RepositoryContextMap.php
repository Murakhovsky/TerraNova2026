<?php
declare(strict_types=1);

namespace App\Engineering\Application\Context;

final readonly class RepositoryContextMap
{
    /** @param list<array{path:string,score:int,snippet:string,size:int}> $files */
    public function __construct(
        public string $repositoryRevision,
        public array $files,
        public array $domains = [],
        public array $modules = [],
        public array $routes = [],
        public array $controllers = [],
        public array $services = [],
        public array $repositories = [],
        public array $entities = [],
        public array $frontend = [],
        public array $tests = [],
        public array $documentation = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'repository_revision' => $this->repositoryRevision,
            'domains' => $this->domains,
            'modules' => $this->modules,
            'routes' => $this->routes,
            'controllers' => $this->controllers,
            'services' => $this->services,
            'repositories' => $this->repositories,
            'entities' => $this->entities,
            'frontend' => $this->frontend,
            'tests' => $this->tests,
            'documentation' => $this->documentation,
            'files' => $this->files,
        ];
    }
}
