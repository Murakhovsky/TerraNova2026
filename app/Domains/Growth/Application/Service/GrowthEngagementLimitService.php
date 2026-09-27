<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\Growth\Application\Contract\GrowthEngagementLimitBoundary;
use Domains\Growth\Application\Contract\GrowthEngagementLimitProfileRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthEngagementLimitProviderInterface;
use Domains\Growth\Application\Contract\GrowthMutationReceiptInterface;
use Domains\Growth\Automation\Event\GrowthEventType;
use Domains\Growth\Domain\EngagementExecutionLimitPolicy;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class GrowthEngagementLimitService implements GrowthEngagementLimitBoundary,GrowthEngagementLimitProviderInterface
{
    public function __construct(
        private GrowthEngagementLimitProfileRepositoryInterface $profiles,
        private GrowthMutationReceiptInterface $receipts,
        private TransactionManagerInterface $transactions,
        private EventBus $events,
        private AuditRepositoryInterface $audit,
        private int $defaultDailyLimit,
        private int $defaultContactCooldownHours,
        private ?int $defaultEmailDailyLimit=null,
        private ?int $defaultLinkedInDailyLimit=null,
        private ?int $defaultPhoneDailyLimit=null,
    ) {
        new EngagementExecutionLimitPolicy(
            $defaultDailyLimit,
            $defaultContactCooldownHours,
            $this->defaultChannelDailyLimits(),
        );
    }

    public function policyFor(string $organizationId):EngagementExecutionLimitPolicy
    {
        $profile=$this->profiles->latest($organizationId);
        return $profile===null
            ? new EngagementExecutionLimitPolicy(
                $this->defaultDailyLimit,
                $this->defaultContactCooldownHours,
                $this->defaultChannelDailyLimits(),
            )
            : new EngagementExecutionLimitPolicy(
                (int)$profile['daily_limit'],
                (int)$profile['contact_cooldown_hours'],
                [
                    'email'=>(int)($profile['email_daily_limit']??$profile['daily_limit']),
                    'linkedin'=>(int)($profile['linkedin_daily_limit']??$profile['daily_limit']),
                    'phone'=>(int)($profile['phone_daily_limit']??$profile['daily_limit']),
                ],
            );
    }

    public function view(string $organizationId):array
    {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $profile=$this->profiles->latest($organizationId);
        $policy=$this->policyFor($organizationId);
        return [
            'source'=>$profile===null?'deployment_default':'tenant_profile',
            'profile'=>$profile,
            'effective'=>[
                'daily_limit'=>$policy->dailyLimit,
                'contact_cooldown_hours'=>$policy->contactCooldownHours,
                'channel_daily_limits'=>$policy->channelDailyLimits,
            ],
            'defaults'=>[
                'daily_limit'=>$this->defaultDailyLimit,
                'contact_cooldown_hours'=>$this->defaultContactCooldownHours,
                'channel_daily_limits'=>$this->defaultChannelDailyLimits(),
            ],
        ];
    }

    public function update(
        string $organizationId,int $actorId,string $correlationId,string $idempotencyKey,array $input
    ):array {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $idempotencyKey=$this->bounded($idempotencyKey,'idempotencyKey',191);
        $dailyLimit=$this->integer($input['daily_limit']??null,'daily_limit');
        $cooldown=$this->integer($input['contact_cooldown_hours']??null,'contact_cooldown_hours');
        $reason=$this->bounded((string)($input['reason']??''),'reason',1000);
        $current=$this->policyFor($organizationId);
        $channelInput=$input['channel_daily_limits']??null;
        if($channelInput!==null&&(!is_array($channelInput)||array_is_list($channelInput))){
            throw new InvalidArgumentException('channel_daily_limits must be an object.');
        }
        $channelLimits=[];
        foreach(['email','linkedin','phone'] as $channel){
            $fallback=min($current->channelDailyLimit($channel),$dailyLimit);
            $channelLimits[$channel]=$this->optionalInteger(
                is_array($channelInput)?($channelInput[$channel]??null):null,
                'channel_daily_limits.'.$channel,
                $fallback,
            );
        }
        new EngagementExecutionLimitPolicy($dailyLimit,$cooldown,$channelLimits);

        $fingerprint=hash('sha256',json_encode([
            'daily_limit'=>$dailyLimit,
            'contact_cooldown_hours'=>$cooldown,
            'channel_daily_limits'=>$channelLimits,
            'reason'=>$reason,
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$idempotencyKey,$dailyLimit,$cooldown,$channelLimits,$reason,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'engagement_limit_profile_update',$idempotencyKey,$fingerprint)){
                return $this->view($organizationId)+['replayed'=>true];
            }

            $latest=$this->profiles->latest($organizationId);
            if(
                $latest!==null
                &&(int)$latest['daily_limit']===$dailyLimit
                &&(int)$latest['contact_cooldown_hours']===$cooldown
                &&(int)($latest['email_daily_limit']??$latest['daily_limit'])===$channelLimits['email']
                &&(int)($latest['linkedin_daily_limit']??$latest['daily_limit'])===$channelLimits['linkedin']
                &&(int)($latest['phone_daily_limit']??$latest['daily_limit'])===$channelLimits['phone']
            ){
                throw new InvalidArgumentException('Growth engagement limits are unchanged.');
            }
            $revision=(int)($latest['revision']??0)+1;
            $profileId='GELP-'.strtoupper(substr(hash('sha256',$organizationId.':'.$revision),0,20));
            $profile=[
                'organization_id'=>$organizationId,
                'profile_id'=>$profileId,
                'revision'=>$revision,
                'daily_limit'=>$dailyLimit,
                'contact_cooldown_hours'=>$cooldown,
                'email_daily_limit'=>$channelLimits['email'],
                'linkedin_daily_limit'=>$channelLimits['linkedin'],
                'phone_daily_limit'=>$channelLimits['phone'],
                'reason'=>$reason,
                'created_by'=>$actorId,
                'created_at'=>$this->now()->format(DATE_ATOM),
            ];
            $this->profiles->append($profile);

            $this->events->publish(new DomainEvent(
                bin2hex(random_bytes(16)),$organizationId,GrowthEventType::ENGAGEMENT_LIMIT_PROFILE_UPDATED,
                'growth_engagement_limit_profile',$profileId,[
                    'revision'=>$revision,
                    'daily_limit'=>$dailyLimit,
                    'contact_cooldown_hours'=>$cooldown,
                    'channel_daily_limits'=>$channelLimits,
                ],
                new EventMetadata($correlationId,null,'USER',(string)$actorId),$this->now(),
            ));
            $this->audit->append(new AuditEntry(
                bin2hex(random_bytes(16)),$organizationId,'growth.engagement_limits','USER',(string)$actorId,
                'growth_engagement_limit_profile',$profileId,null,[
                    'action'=>'growth.engagement_limits.updated',
                    'idempotency_key_hash'=>hash('sha256',$idempotencyKey),
                    'result'=>[
                        'revision'=>$revision,
                        'daily_limit'=>$dailyLimit,
                        'contact_cooldown_hours'=>$cooldown,
                        'channel_daily_limits'=>$channelLimits,
                        'reason'=>$reason,
                    ],
                ],$correlationId,$this->now(),
            ));

            return $this->view($organizationId);
        });
    }

    private function integer(mixed $value,string $field):int
    {
        if(is_int($value))return $value;
        if(is_string($value)&&ctype_digit(trim($value)))return (int)trim($value);
        throw new InvalidArgumentException($field.' must be an integer.');
    }

    private function optionalInteger(mixed $value,string $field,int $fallback):int
    {
        if($value===null)return $fallback;
        return $this->integer($value,$field);
    }

    /** @return array{email:int,linkedin:int,phone:int} */
    private function defaultChannelDailyLimits():array
    {
        return [
            'email'=>$this->defaultEmailDailyLimit??$this->defaultDailyLimit,
            'linkedin'=>$this->defaultLinkedInDailyLimit??$this->defaultDailyLimit,
            'phone'=>$this->defaultPhoneDailyLimit??$this->defaultDailyLimit,
        ];
    }

    private function bounded(string $value,string $field,int $limit):string
    {
        $value=trim($value);
        if($value===''||mb_strlen($value)>$limit)throw new InvalidArgumentException($field.' is invalid.');
        return $value;
    }

    private function now():DateTimeImmutable{return new DateTimeImmutable('now',new DateTimeZone('UTC'));}
}
