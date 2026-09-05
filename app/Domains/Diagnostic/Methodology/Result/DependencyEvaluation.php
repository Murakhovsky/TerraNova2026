<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Methodology\Result;
final readonly class DependencyEvaluation
{
    /** @param list<string> $missingDependencies @param list<string> $blockingDependencies */
    public function __construct(public string $node, public string $status, public array $missingDependencies=[], public array $blockingDependencies=[]) {}
}
