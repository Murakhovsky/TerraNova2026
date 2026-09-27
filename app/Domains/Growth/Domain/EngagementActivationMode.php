<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

enum EngagementActivationMode:string
{
    case Blocked='blocked';
    case ApprovalRequired='approval_required';
    case Auto='auto';

    public function canPropose():bool
    {
        return $this!==self::Blocked;
    }

    public function executionMode():string
    {
        return match($this){
            self::Blocked=>'MANUAL',
            self::ApprovalRequired=>'APPROVAL_REQUIRED',
            self::Auto=>'AUTO',
        };
    }
}
