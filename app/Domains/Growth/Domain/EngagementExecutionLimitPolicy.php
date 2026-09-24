<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class EngagementExecutionLimitPolicy
{
    public function __construct(
        public int $dailyLimit,
        public int $contactCooldownHours,
    ) {
        if($dailyLimit<1||$dailyLimit>100000)throw new InvalidArgumentException('Growth daily outreach limit must be between 1 and 100000.');
        if($contactCooldownHours<1||$contactCooldownHours>8760)throw new InvalidArgumentException('Growth contact cooldown must be between 1 and 8760 hours.');
    }

    /** @return array{allowed:bool,code:string,reason:string,next_allowed_at:?string} */
    public function evaluate(int $executionsToday,?DateTimeImmutable $lastContactExecution,DateTimeImmutable $now):array
    {
        if($executionsToday>=$this->dailyLimit){
            return [
                'allowed'=>false,
                'code'=>'pre_handoff_daily_limit_reached',
                'reason'=>'Pre-handoff outreach daily limit has been reached.',
                'next_allowed_at'=>$now->setTime(0,0)->modify('+1 day')->format(DATE_ATOM),
            ];
        }

        if($lastContactExecution!==null){
            $next=$lastContactExecution->add(new DateInterval('PT'.$this->contactCooldownHours.'H'));
            if($next>$now){
                return [
                    'allowed'=>false,
                    'code'=>'pre_handoff_contact_cooldown',
                    'reason'=>'This Growth contact is still inside the pre-handoff outreach cooldown.',
                    'next_allowed_at'=>$next->format(DATE_ATOM),
                ];
            }
        }

        return [
            'allowed'=>true,
            'code'=>'pre_handoff_limits_ok',
            'reason'=>'Pre-handoff outreach is inside configured volume and contact cooldown limits.',
            'next_allowed_at'=>null,
        ];
    }
}
