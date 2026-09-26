<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

enum HandoffAttemptStatus: string
{
    case Running = 'running';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Failed = 'failed';
}
