<?php
declare(strict_types=1);

namespace App\Engineering\Application\Context;

final readonly class EngineeringStandardsProvider
{
    private const PATHS = [
        'docs/06-ai-agents/engineering/architecture-standard.md',
        'docs/06-ai-agents/engineering/coding-standard.md',
        'docs/06-ai-agents/engineering/security-standard.md',
        'docs/06-ai-agents/engineering/testing-standard.md',
        'docs/06-ai-agents/engineering/git-standard.md',
        'docs/06-ai-agents/engineering/definition-of-done.md',
        'docs/06-ai-agents/engineering/agent-permissions.md',
        'docs/06-ai-agents/engineering/learning-loop.md',
    ];

    public function __construct(private RepositoryFileReaderInterface $repositoryFiles) {}

    /** @return list<array{path:string,content:string,complete:bool,size:int,sha256:string}> */
    public function all(): array
    {
        return $this->repositoryFiles->readMany(self::PATHS);
    }

    /** @return list<string> */
    public function paths(): array
    {
        return self::PATHS;
    }
}
