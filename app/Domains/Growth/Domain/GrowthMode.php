<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

enum GrowthMode: string
{
    case Acquire = 'acquire';
    case Expand = 'expand';
    case Reactivate = 'reactivate';
    case Discover = 'discover';
}
