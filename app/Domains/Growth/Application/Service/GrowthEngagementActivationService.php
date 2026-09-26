<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\Growth\Application\Contract\GrowthEngagementActivationBoundary;
use Domains\Growth\Application\Contract\GrowthEngagementActivationProfileRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthEngagementActivationProviderInterface;
use Domains\Growth\Application\Contract\GrowthEngagementExecutionRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthMutationReceiptInterface;
use Domains\Growth\Automation\Event\GrowthEventType;
use Domains\Growth\Domain\EngagementActivationMode;
use Domains\Growth\Domain\EngagementChannel;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class GrowthEngagementActivationService implements GrowthEngagementActivationBoundary,GrowthEngagementActivationProviderInterface
{
    public function __construct(
        private GrowthEngagementActivationProfileRepositoryInterface $profiles,
        private GrowthEngagementExecutionRepositoryInterface $executions,
        private GrowthMutationReceiptInterface $receipts,
        private TransactionManagerInterface $transactions,
        private EventBus $events,
        private AuditRepositoryInterface $audit,
    ) {}

    public function modeFor(string $organizationId,string $channel):EngagementActivationMode
    {
        $channel=$this->channel($channel);
        $profile=$this->profiles->latest($organizationId);
        if($profile===null)return EngagementActivationMode::ApprovalRequired;
        return EngagementActivationMode::tryFrom((string)($profile[$channel.'_mode']??''))
            ?? throw new InvalidArgumentException('Stored Growth engagement activation mode is invalid.');
    }

    public function view(string $organizationId):array
    {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $profile=$this->profiles->latest($organizationId);
        $modes=[];
        foreach(['email','linkedin','phone'] as $channel){
            $modes[$channel]=$profile===null
                ? EngagementActivationMode::ApprovalRequired->value
                : $this->modeFor($organizationId,$channel)->value;
        }
        $defaults=['email'=>'approval_required','linkedin'=>'approval_required','phone'=>'approval_required'];
        return [
            'source'=>$profile===null?'safe_default':'tenant_profile',
            'profile'=>$profile,
            'effective'=>['channel_modes'=>$modes],
            'defaults'=>['channel_modes'=>$defaults],
        ];
    }

    public function update(string $organizationId,int $actorId,string $correlationId,string $idempotencyKey,array $input):array
    {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $idempotencyKey=$this->bounded($idempotencyKey,'idempotencyKey',191);
        $reason=$this->bounded((string)($input['reason']??''),'reason',1000);
        $channelModes=$input['channel_modes']??null;
        if(!is_array($channelModes)||array_is_list($channelModes)){
            throw new InvalidArgumentException('channel_modes must be an object.');
        }

        $normalized=[];
        foreach(['email','linkedin','phone'] as $channel){
            $value=$channelModes[$channel]??null;
            if(!is_string($value))throw new InvalidArgumentException('channel_modes.'.$channel.' is required.');
            $mode=EngagementActivationMode::tryFrom(strtolower(trim($value)));
            if($mode===null)throw new InvalidArgumentException('channel_modes.'.$channel.' must be blocked, approval_required or auto.');
            $normalized[$channel]=$mode->value;
        }

        $fingerprint=hash('sha256',json_encode(['channel_modes'=>$normalized,'reason'=>$reason],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$idempotencyKey,$normalized,$reason,$fingerprint
        ):array{
            $this->executions->lockPreHandoffCapacity($organizationId);

            if(!$this->receipts->claim($organizationId,'engagement_activation_profile_update',$idempotencyKey,$fingerprint)){
                return $this->view($organizationId)+['replayed'=>true];
            }

            $latest=$this->profiles->latest($organizationId);
            if(
                $latest!==null
                &&(string)$latest['email_mode']===$normalized['email']
                &&(string)$latest['linkedin_mode']===$normalized['linkedin']
                &&(string)$latest['phone_mode']===$normalized['phone']
            ){
                throw new InvalidArgumentException('Growth engagement activation policy is unchanged.');
            }

            $revision=(int)($latest['revision']??0)+1;
            $profileId='GEAP-'.strtoupper(substr(hash('sha256',$organizationId.':'.$revision),0,20));
            $profile=[
                'organization_id'=>$organizationId,
                'profile_id'=>$profileId,
                'revision'=>$revision,
                'email_mode'=>$normalized['email'],
                'linkedin_mode'=>$normalized['linkedin'],
                'phone_mode'=>$normalized['phone'],
                'reason'=>$reason,
                'created_by'=>$actorId,
                'created_at'=>$this->now()->format(DATE_ATOM),
            ];
            $this->profiles->append($profile);

            $this->events->publish(new DomainEvent(
                bin2hex(random_bytes(16)),$organizationId,GrowthEventType::ENGAGEMENT_ACTIVATION_PROFILE_UPDATED,
                'growth_engagement_activation_profile',$profileId,['revision'=>$revision,'channel_modes'=>$normalized],
                new EventMetadata($correlationId,null,'USER',(string)$actorId),$this->now(),
            ));
            $this->audit->append(new AuditEntry(
                bin2hex(random_bytes(16)),$organizationId,'growth.engagement_activation','USER',(string)$actorId,
                'growth_engagement_activation_profile',$profileId,null,[
                    'action'=>'growth.engagement_activation.updated',
                    'idempotency_key_hash'=>hash('sha256',$idempotencyKey),
                    'result'=>['revision'=>$revision,'channel_modes'=>$normalized,'reason'=>$reason],
                ],$correlationId,$this->now(),
            ));

            return $this->view($organizationId);
        });
    }

    private function channel(string $channel):string
    {
        $channel=strtolower(trim($channel));
        $value=EngagementChannel::tryFrom($channel);
        if($value===null||!in_array($value,[EngagementChannel::Email,EngagementChannel::LinkedIn,EngagementChannel::Phone],true)){
            throw new InvalidArgumentException('Unsupported Growth outreach channel.');
        }
        return $value->value;
    }

    private function bounded(string $value,string $field,int $limit):string
    {
        $value=trim($value);
        if($value===''||mb_strlen($value)>$limit)throw new InvalidArgumentException($field.' is invalid.');
        return $value;
    }

    private function now():DateTimeImmutable{return new DateTimeImmutable('now',new DateTimeZone('UTC'));}
}
