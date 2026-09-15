<?php
declare(strict_types=1);

namespace Kernel\Visualization\Graph;

interface GraphProviderInterface
{
    public function provide(): Graph;
}
