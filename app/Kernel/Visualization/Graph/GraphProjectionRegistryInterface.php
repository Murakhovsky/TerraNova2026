<?php
declare(strict_types=1);

namespace Kernel\Visualization\Graph;

interface GraphProjectionRegistryInterface
{
    /** @return list<string> */
    public function names(): array;

    /**
     * Renderer-neutral projection hints.
     *
     * @return array<string, array{label:string,layout?:string,default_depth?:?int}>
     */
    public function descriptions(): array;

    public function has(string $name): bool;

    public function project(string $name, Graph $graph, GraphView $view): Graph;
}
