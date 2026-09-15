<?php
declare(strict_types=1);

namespace Kernel\Visualization\Graph;

interface GraphProjectionInterface
{
    public function project(Graph $graph, GraphView $view): Graph;
}
