<?php
declare(strict_types=1);

namespace App\Web\Experience\Delivery;

enum ExperienceAutonomyLevel: string
{
    case L0 = 'L0';
    case L1 = 'L1';
    case L2 = 'L2';
    case L3 = 'L3';
    case L4 = 'L4';

    public function rank(): int
    {
        return (int) substr($this->value, 1);
    }
}
