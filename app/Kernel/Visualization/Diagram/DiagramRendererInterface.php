<?php
declare(strict_types=1);

namespace Kernel\Visualization\Diagram;

interface DiagramRendererInterface
{
    public function render(Diagram $diagram): string;
}
