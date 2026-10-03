<?php
declare(strict_types=1);

namespace App\Engineering\Domain\Feature;

enum EngineeringPriority: string
{
    case P0 = 'P0';
    case P1 = 'P1';
    case P2 = 'P2';
    case P3 = 'P3';
}
