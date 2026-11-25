<?php
declare(strict_types=1);

namespace Domains\Sales\Domain\Policy;

/** Early lead qualification is monotonic. Reopening is a separate business operation. */
final class LeadQualificationPolicy
{
    public static function allows(string $from,string $to):bool
    {
        return in_array($to,match($from){
            'new'=>['contacted','qualified','disqualified'],
            'contacted'=>['qualified','disqualified'],
            'qualified'=>['disqualified'],
            default=>[],
        },true);
    }

    public static function terminal(string $status):bool
    {
        return in_array($status,['disqualified','spam','won','lost','closed'],true);
    }
}
