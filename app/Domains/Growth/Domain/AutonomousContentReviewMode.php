<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

enum AutonomousContentReviewMode:string
{
    case Blocked='blocked';
    case HumanReview='human_review';
    case PolicyAutoApprove='policy_auto_approve';

    public function canDraft():bool{return $this!==self::Blocked;}
    public function canHumanApprove():bool{return $this!==self::Blocked;}
    public function canAutoApprove():bool{return $this===self::PolicyAutoApprove;}
}
