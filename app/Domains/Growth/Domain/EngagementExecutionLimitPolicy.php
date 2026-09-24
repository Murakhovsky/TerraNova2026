<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class EngagementExecutionLimitPolicy
{
    /** @var array{email:int,linkedin:int,phone:int} */
    public array $channelDailyLimits;

    /** @param array<string,int>|null $channelDailyLimits */
    public function __construct(
        public int $dailyLimit,
        public int $contactCooldownHours,
        ?array $channelDailyLimits=null,
    ) {
        if($dailyLimit<1||$dailyLimit>100000){
            throw new InvalidArgumentException('Growth daily outreach limit must be between 1 and 100000.');
        }
        if($contactCooldownHours<1||$contactCooldownHours>8760){
            throw new InvalidArgumentException('Growth contact cooldown must be between 1 and 8760 hours.');
        }

        $channelDailyLimits??=[
            EngagementChannel::Email->value=>$dailyLimit,
            EngagementChannel::LinkedIn->value=>$dailyLimit,
            EngagementChannel::Phone->value=>$dailyLimit,
        ];
        $normalized=[];
        foreach([EngagementChannel::Email,EngagementChannel::LinkedIn,EngagementChannel::Phone] as $channel){
            $value=$channelDailyLimits[$channel->value]??null;
            if(!is_int($value)||$value<0||$value>100000){
                throw new InvalidArgumentException('Growth '.$channel->value.' daily outreach limit must be between 0 and 100000.');
            }
            $normalized[$channel->value]=$value;
        }
        $this->channelDailyLimits=$normalized;
    }

    public function channelDailyLimit(string $channel):int
    {
        if(!array_key_exists($channel,$this->channelDailyLimits)){
            throw new InvalidArgumentException('Unsupported Growth outreach channel.');
        }
        return $this->channelDailyLimits[$channel];
    }

    /**
     * @return array{
     *   allowed:bool,code:string,reason:string,next_allowed_at:?string,
     *   scope:string,channel:?string,used:int,limit:int
     * }
     */
    public function evaluate(
        int $executionsToday,
        ?DateTimeImmutable $lastContactExecution,
        DateTimeImmutable $now,
        ?string $channel=null,
        ?int $channelExecutionsToday=null,
    ):array {
        if($executionsToday<0)throw new InvalidArgumentException('Growth daily outreach usage cannot be negative.');

        if($executionsToday>=$this->dailyLimit){
            return [
                'allowed'=>false,
                'code'=>'pre_handoff_daily_limit_reached',
                'reason'=>'Pre-handoff outreach daily limit has been reached.',
                'next_allowed_at'=>$now->setTime(0,0)->modify('+1 day')->format(DATE_ATOM),
                'scope'=>'organization',
                'channel'=>null,
                'used'=>$executionsToday,
                'limit'=>$this->dailyLimit,
            ];
        }

        if($channel!==null){
            $channelLimit=$this->channelDailyLimit($channel);
            if($channelExecutionsToday===null||$channelExecutionsToday<0){
                throw new InvalidArgumentException('Growth channel outreach usage is required and cannot be negative.');
            }
            if($channelExecutionsToday>=$channelLimit){
                return [
                    'allowed'=>false,
                    'code'=>'pre_handoff_channel_daily_limit_reached',
                    'reason'=>'Pre-handoff '.$channel.' daily outreach quota has been reached.',
                    'next_allowed_at'=>$now->setTime(0,0)->modify('+1 day')->format(DATE_ATOM),
                    'scope'=>'channel',
                    'channel'=>$channel,
                    'used'=>$channelExecutionsToday,
                    'limit'=>$channelLimit,
                ];
            }
        }

        if($lastContactExecution!==null){
            $next=$lastContactExecution->add(new DateInterval('PT'.$this->contactCooldownHours.'H'));
            if($next>$now){
                return [
                    'allowed'=>false,
                    'code'=>'pre_handoff_contact_cooldown',
                    'reason'=>'This Growth contact is still inside the pre-handoff outreach cooldown.',
                    'next_allowed_at'=>$next->format(DATE_ATOM),
                    'scope'=>'contact',
                    'channel'=>$channel,
                    'used'=>0,
                    'limit'=>$this->contactCooldownHours,
                ];
            }
        }

        return [
            'allowed'=>true,
            'code'=>'pre_handoff_limits_ok',
            'reason'=>'Pre-handoff outreach is inside configured organization, channel and contact limits.',
            'next_allowed_at'=>null,
            'scope'=>'none',
            'channel'=>$channel,
            'used'=>$channelExecutionsToday??$executionsToday,
            'limit'=>$channel===null?$this->dailyLimit:$this->channelDailyLimit($channel),
        ];
    }
}
