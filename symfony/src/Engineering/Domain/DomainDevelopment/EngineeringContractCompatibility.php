<?php
declare(strict_types=1);

namespace App\Engineering\Domain\DomainDevelopment;

enum EngineeringContractCompatibility: string
{
    case BACKWARD_COMPATIBLE = 'BACKWARD_COMPATIBLE';
    case BREAKING = 'BREAKING';
    case DEPRECATED = 'DEPRECATED';
}
