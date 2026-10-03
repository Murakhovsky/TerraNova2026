<?php
declare(strict_types=1);

namespace App\Engineering\Domain\Artifact;

enum ArtifactStatus: string
{
    case ACTIVE = 'ACTIVE';
    case SUPERSEDED = 'SUPERSEDED';
    case INVALID = 'INVALID';
}
