<?php
declare(strict_types=1);

namespace Kernel\Workflow\Model;

enum AssignmentType: string
{
    case USER = 'user';
    case ROLE = 'role';
    case TEAM = 'team';
}
