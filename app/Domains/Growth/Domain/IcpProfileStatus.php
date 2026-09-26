<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

enum IcpProfileStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
