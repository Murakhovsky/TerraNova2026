<?php
declare(strict_types=1);

namespace App\Engineering\Domain\Feature;

enum EngineeringComplexity: string
{
    case XS = 'XS';
    case S = 'S';
    case M = 'M';
    case L = 'L';
    case XL = 'XL';
}
