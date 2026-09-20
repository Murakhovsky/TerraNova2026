<?php
declare(strict_types=1);

namespace App\Web\Experience\Action;

enum UIActionIntent: string
{
    case View = 'view';
    case Create = 'create';
    case Edit = 'edit';
    case Execute = 'execute';
    case Approve = 'approve';
    case Reject = 'reject';
    case Archive = 'archive';
    case Delete = 'delete';
    case Danger = 'danger';
}
