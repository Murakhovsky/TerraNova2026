<?php

declare(strict_types=1);

namespace App\Web\Experience\Visual;

enum VisualStability: string
{
    case Experimental = 'experimental';
    case Stable = 'stable';
    case Deprecated = 'deprecated';
}
