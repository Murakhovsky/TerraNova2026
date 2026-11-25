<?php
declare(strict_types=1);

namespace Domains\Sales\Domain\Policy;

/** Lead classification is not a command to close or reassign a Deal. */
final class LeadLifecyclePolicy
{
    public static function allows(string $from,string $to):bool
    {
        if (LeadQualificationPolicy::allows($from,$to)) return true;
        $next=match($from){
            'new','contacted'=>['spam','closed','lost'],
            'qualified'=>['viewing_planned','viewing','negotiation','lost','closed','spam','won'],
            'viewing_planned'=>['viewing','negotiation','disqualified','lost','closed','spam','won'],
            'viewing'=>['viewing_planned','negotiation','disqualified','lost','closed','spam','won'],
            'negotiation'=>['viewing_planned','viewing','disqualified','lost','closed','spam','won'],
            default=>[],
        };
        return in_array($to,$next,true);
    }
}
